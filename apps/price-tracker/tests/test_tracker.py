import csv
from datetime import datetime, timezone, timedelta
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch
from tracker.parsers import parse, money, canonical
from tracker.matching import match
from tracker.http import Client, SourceError
from tracker.storage import connect, save, export, csv_write

FIXTURES = Path(__file__).parent/'fixtures'
CATALOG = json.loads((Path(__file__).parent.parent/'config/catalog.json').read_text())
GPU = next(m for m in CATALOG if m['id']=='rtx-3060-12gb')
CPU = next(m for m in CATALOG if m['id']=='ryzen-5-7600')


def row(title='MSI RTX 3060 12GB', **kw):
    return dict(title=title,price_eur=200,condition='used',availability='listed',delivery_bg='confirmed',price_kind='asking',**kw)


class ParsingTests(unittest.TestCase):
    def test_currency_and_dual_display(self):
        self.assertEqual(money('1 955,83 лв.')[2],1000)
        self.assertEqual(money('1.234,56 €')[2],1234.56)
        self.assertEqual(money('391.17 лв. / 200 €')[2],200)
        self.assertEqual(money('2,000.50','EUR')[2],2000.50)
        self.assertEqual(money('200','EUR')[2],200)
        for value in ('Call us','безплатно','NaN','Infinity','0'):
            with self.assertRaises(ValueError): money(value,'EUR')

    def test_real_olx_fixture(self):
        rows,_=parse('olx',(FIXTURES/'olx.html').read_text(),'https://www.olx.bg/ads/q-rtx-3060/')
        self.assertGreaterEqual(len(rows),5)
        card=next(r for r in rows if r['title']=='Видео карта MSI RTX 3060 Ventus 2x 12GB')
        self.assertEqual(card['price_eur'],240)
        self.assertEqual(card['condition'],'used')
        self.assertEqual(card['delivery_bg'],'confirmed')
        self.assertNotIn('search_reason',card['url'])
        self.assertEqual(match(card,GPU)[0],'accepted')

    def test_real_desktop_fixture(self):
        rows,_=parse('desktop',(FIXTURES/'desktop.html').read_text(),'https://desktop.bg/search?q=Ryzen+5+7600')
        card=next(r for r in rows if r['title']=='AMD Ryzen 5 7600 MPK')
        self.assertEqual(card['price_eur'],203)
        self.assertEqual(card['availability'],'in_stock')
        self.assertEqual(match(card,CPU)[0],'accepted')
        x=next(r for r in rows if r['title']=='AMD Ryzen 5 7600X Tray')
        self.assertEqual(match(x,CPU)[0],'rejected')

    def test_jsonld_and_challenge(self):
        document='<script type="application/ld+json">'+json.dumps({'@type':'Product','name':'RTX 3060 12GB','offers':{'@type':'AggregateOffer','lowPrice':'220','priceCurrency':'EUR'}})+'</script>'
        rows,_=parse('pazaruvaj',document,'https://www.pazaruvaj.com/p/test/')
        self.assertEqual(rows[0]['price_kind'],'aggregate_from')
        for document in ('<html><body>Performing security verification</body></html>','<html>Changed layout</html>'):
            with self.assertRaises(ValueError): parse('olx',document,'https://www.olx.bg/')

    def test_currency_required(self):
        with self.assertRaises(ValueError): money('200')
        self.assertEqual(canonical('javascript:alert(1)'),'')
        self.assertEqual(canonical('https://a.bg/x?tracking=1#foo'),'https://a.bg/x')


class MatchingTests(unittest.TestCase):
    def test_gpu_variants(self):
        for title in ('RTX 3060 Ti 12GB','RTX 3060 8GB','RTX 3060 Super 12GB','RTX 3060 12GB + RTX 3070 8GB'):
            self.assertNotEqual(match(row(title),GPU)[0],'accepted')
        self.assertEqual(match(row('MSI RTX 3060'),GPU),('review','missing_vram'))
        self.assertEqual(match(row('GeForce RTX™ 3060 OC 12GB'),GPU)[0],'accepted')

    def test_bundles_and_parts(self):
        for title in ('Геймърски компютър RTX 3060 12GB','RTX 3060 12GB Ryzen 5 5600','RTX 3060 12GB за части','Вентилатор RTX 3060 12GB','купувам RTX 3060 12GB','RTX 3060 12GB 3 бр'):
            self.assertNotEqual(match(row(title),GPU)[0],'accepted')

    def test_delivery_and_condition(self):
        r=row();r['delivery_bg']='seller_confirmation'
        self.assertEqual(match(r,GPU),('review','delivery_to_bulgaria_unconfirmed'))
        r=row();r['condition']='unknown'
        self.assertEqual(match(r,GPU)[0],'review')


class StorageTests(unittest.TestCase):
    def test_idempotency_and_failed_source(self):
        with tempfile.TemporaryDirectory() as tmp:
            db=connect(':memory:')
            stamp=datetime.now(timezone.utc).isoformat()
            r=row(model_id=GPU['id'],source='olx',url='https://www.olx.bg/d/ad/test',observed_at=stamp,run_id='one',status='accepted')
            save(db,[r,r]); self.assertEqual(db.execute('SELECT count(*) FROM observations').fetchone()[0],1)
            run=dict(run_id='one',finished_at=stamp,jobs=[dict(source='olx',model_id=GPU['id'],status='ok')])
            s=export(db,[GPU],tmp,run)
            self.assertEqual(next(x for x in s if x['condition']=='used')['sample_count'],1)
            run['run_id']='two';run['jobs'][0]['status']='error'
            s=export(db,[GPU],tmp,run)
            self.assertEqual(next(x for x in s if x['condition']=='used')['sample_count'],0)
            self.assertEqual(db.execute('SELECT count(*) FROM observations').fetchone()[0],1)

    def test_absent_and_stale_listings(self):
        with tempfile.TemporaryDirectory() as tmp:
            db=connect(':memory:')
            now=datetime.now(timezone.utc)
            r=row(model_id=GPU['id'],source='olx',url='https://www.olx.bg/d/ad/test',observed_at=(now-timedelta(days=3)).isoformat(),run_id='old',status='accepted')
            save(db,[r])
            s=export(db,[GPU],tmp,dict(run_id='new',finished_at=now.isoformat(),jobs=[dict(source='olx',model_id=GPU['id'],status='ok')]))
            self.assertFalse(any(x['sample_count'] for x in s))

    def test_csv_injection(self):
        with tempfile.TemporaryDirectory() as tmp:
            path=Path(tmp)/'a.csv';csv_write(path,[{'title':'=HYPERLINK("x")','price':10}],['title','price'])
            with path.open() as f:
                r=list(csv.DictReader(f))[0]
            self.assertTrue(r['title'].startswith("'="))


class NetworkTests(unittest.TestCase):
    def test_host_allowlist(self):
        c=Client(['desktop.bg'])
        for u in ('http://desktop.bg/','https://127.0.0.1/','https://desktop.bg@evil.com/','https://desktop.bg:8443/'):
            with self.assertRaises(SourceError): c.get(u)

    def test_robots_blocks_requests(self):
        c=Client(['desktop.bg'])
        with patch.object(c,'request',return_value='User-agent: *\nDisallow: /search') as get:
            with self.assertRaisesRegex(SourceError,'robots_disallowed'): c.get('https://desktop.bg/search?q=cpu')
            self.assertEqual(get.call_count,1)


if __name__=='__main__': unittest.main()
