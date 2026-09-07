import argparse
from datetime import datetime, timezone
import json
import os
from pathlib import Path
from urllib.parse import quote, quote_plus, urlsplit
from uuid import uuid4
from .http import Client, SourceError
from .matching import match
from .parsers import parse
from .storage import connect, save, export


def load_config(path):
    config = json.loads(Path(path).read_text())
    if isinstance(config,list):
        ids = [m['id'] for m in config]
        if len(ids) != len(set(ids)):
            raise ValueError('Duplicate model IDs')
        for m in config:
            if not m.get('name') or not m.get('query') or not m.get('category'):
                raise ValueError('Model requires id, name, query and category')
    return config


def main():
    p = argparse.ArgumentParser(description='Collect Bulgaria PC component price observations')
    p.add_argument('--catalog',default='config/catalog.json')
    p.add_argument('--sources',default='config/sources.json')
    p.add_argument('--database',default='prices.sqlite')
    p.add_argument('--output',default='output')
    p.add_argument('--model',help='Only this catalog id')
    p.add_argument('--source',help='Only this source')
    p.add_argument('--fixtures',help='Offline parser smoke test; output marked fixture and never uploaded')
    p.add_argument('--max-pages',type=int,default=2)
    args = p.parse_args()
    if not 1 <= args.max_pages <= 10:
        p.error('--max-pages must be 1..10')
    catalog = load_config(args.catalog)
    sources = load_config(args.sources)
    selected = [m for m in catalog if not args.model or m['id']==args.model]
    if not selected:
        p.error('Unknown model')
    if args.source and args.source not in sources:
        p.error('Unknown source')
    run_id = str(uuid4())
    stamp = datetime.now(timezone.utc).isoformat()
    run = dict(run_id=run_id,started_at=stamp,mode='fixture' if args.fixtures else 'live',jobs=[])
    db = connect(args.database)
    all_rows = []
    for source, cfg in sources.items():
        if args.source and source != args.source:
            continue
        if not cfg['enabled']:
            continue
        client = Client(cfg['hosts'])
        circuit = None
        for model in selected:
            job = dict(source=source,model_id=model['id'],status='ok',detail='',pages=0,rows=0,truncated=False)
            rows = []
            url = cfg['search_url'].format(query=quote_plus(model['query']),slug=quote(model['query'].lower().replace(' ','-')))
            url = model.get('source_urls',{}).get(source,url)
            if circuit:
                job.update(status='skipped',detail='source_circuit_open: '+circuit)
            else:
                try:
                    visited = set()
                    while url and job['pages'] < args.max_pages:
                        if url in visited:
                            raise SourceError('pagination_loop')
                        visited.add(url)
                        if args.fixtures:
                            fixture = Path(args.fixtures)/(source+'.html')
                            if not fixture.exists():
                                raise SourceError('fixture_unavailable')
                            body = fixture.read_text()
                        else:
                            body = client.get(url)
                        parsed, next_url = parse(source,body,url)
                        for row in parsed:
                            if urlsplit(row['url']).hostname not in cfg['hosts']:
                                continue
                            row.update(source=source,model_id=model['id'],category=model['category'],observed_at=stamp,run_id=run_id)
                            row['status'],row['reason'] = match(row,model)
                            if row['status'] != 'rejected':
                                rows.append(row)
                        job['pages'] += 1
                        if args.fixtures:
                            next_url = None
                        if next_url and urlsplit(next_url).hostname not in cfg['hosts']:
                            raise SourceError('pagination_host_changed')
                        url = next_url
                    job['truncated'] = bool(url)
                    job['rows'] = len(rows)
                    if job['truncated']:
                        job['detail'] = 'page_limit_reached; bounded sample'
                except (SourceError, ValueError) as exc:
                    detail = str(exc)[:160]
                    job.update(status='error',detail=detail,rows=len(rows))
                    # Keep diagnostic rows but never summarize a partially failed query.
                    if detail != 'fixture_unavailable':
                        circuit = detail
            all_rows.extend(rows)
            run['jobs'].append(job)
            print(f"{source} {model['id']}: {job['status']} ({job['rows']}) {job['detail']}",flush=True)
    save(db,all_rows)
    run['finished_at'] = datetime.now(timezone.utc).isoformat()
    run['successful_jobs'] = sum(j['status']=='ok' for j in run['jobs'])
    run['failed_jobs'] = sum(j['status']!='ok' for j in run['jobs'])
    run['collected_rows'] = len(all_rows)
    run['health'] = 'ok' if run['failed_jobs']==0 and run['successful_jobs'] else 'partial' if run['successful_jobs'] else 'failed'
    summaries = export(db,catalog,args.output,run)
    # Markdown report is readable directly in GitHub, no hosting setup required.
    report = ['# Component price tracker','',f"Run: {stamp} | Mode: {run['mode']} | Health: **{run['health']}**",'',
              'Prices in EUR. Used prices are asking prices, not completed sales. Shipping is excluded unless known.','',
              '| Component | Condition | Samples | Median EUR | Confidence |','|---|---|---:|---:|---|']
    for s in summaries:
        if s['sample_count']:
            report.append(f"| {s['name']} | {s['condition']} | {s['sample_count']} | {s['median_eur']} | {s['confidence']} |")
    if not any(s['sample_count'] for s in summaries):
        report.extend(['','No verified, delivery-confirmed component offers in this run. See listings.csv and review.csv for observations requiring review.'])
    report.extend(['','[Source status](source_status.csv) · [All listings](listings.csv) · [Review queue](review.csv) · [Price history](history.csv)','',
                   'The catalog and page limits define coverage. This is a sample, not an exhaustive crawl. Failed/old observations do not feed current summaries.'])
    Path(args.output,'README.md').write_text('\n'.join(report)+'\n')
    if os.getenv('GITHUB_STEP_SUMMARY'):
        Path(os.environ['GITHUB_STEP_SUMMARY']).write_text('\n'.join(report))
    db.close()
    return 0 if run['successful_jobs'] else 2


if __name__ == '__main__':
    raise SystemExit(main())
