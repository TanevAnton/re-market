"""Small, source-specific HTML adapters. Never infer a price from the whole page."""
import json
import re
from decimal import Decimal, InvalidOperation
from urllib.parse import urljoin, urlsplit, urlunsplit
from lxml import html

BGN_PER_EUR = Decimal('1.95583')


def canonical(url):
    p = urlsplit(url)
    if p.scheme != 'https' or not p.hostname or p.username or p.password:
        return ''
    return urlunsplit((p.scheme, p.netloc.lower(), p.path, '', ''))


def money(value, currency=None):
    """Return (original amount, currency, EUR); reject unlabelled/invalid money."""
    s = str(value).replace('\xa0', ' ').replace('\u202f', ' ').strip()
    if not currency:
        # Prefer EUR when a source renders both currencies.
        match = re.search(r'([\d][\d .,]*)\s*(€|EUR)', s, re.I)
        if not match:
            match = re.search(r'([\d][\d .,]*)\s*(лв\.?|BGN)', s, re.I)
        if not match:
            raise ValueError('missing_currency')
        s, currency = match.group(1), match.group(2)
    currency = 'EUR' if currency.upper() in ('€', 'EUR') else 'BGN' if currency.upper() in ('ЛВ', 'ЛВ.', 'BGN') else currency
    if currency not in ('EUR', 'BGN'):
        raise ValueError('unsupported_currency')
    s = s.replace(' ', '')
    if ',' in s and '.' in s:
        decimal = ',' if s.rfind(',') > s.rfind('.') else '.'
        s = s.replace('.' if decimal == ',' else ',', '').replace(',', '.')
    elif ',' in s:
        s = s.replace(',', '.') if len(s.rsplit(',', 1)[-1]) <= 2 else s.replace(',', '')
    elif s.count('.') > 1 or ('.' in s and len(s.rsplit('.', 1)[-1]) == 3):
        s = s.replace('.', '')
    try:
        amount = Decimal(s)
    except InvalidOperation as exc:
        raise ValueError('invalid_price') from exc
    if not amount.is_finite() or amount <= 0 or amount > 100000:
        raise ValueError('invalid_price')
    eur = amount if currency == 'EUR' else amount / BGN_PER_EUR
    return float(amount), currency, float(eur.quantize(Decimal('.01')))


def text(node):
    return ' '.join(node.text_content().split()) if node is not None else ''


def first(node, xpath):
    found = node.xpath(xpath)
    return found[0] if found else None


def prop(node, name):
    e = first(node, './/*[@itemprop="' + name + '"]')
    return (e.get('content') or e.get('href') or text(e)) if e is not None else ''


def availability(value):
    v = value.lower()
    if 'outofstock' in v or 'discontinued' in v or 'изчерпан' in v:
        return 'out_of_stock'
    if 'instock' in v or 'limitedavailability' in v:
        return 'in_stock'
    if 'preorder' in v or 'backorder' in v:
        return 'preorder'
    return 'unknown'


def listing(title, url, value, currency=None, **extra):
    amount, currency, eur = money(value, currency)
    url = canonical(url)
    if not title or not url:
        raise ValueError('missing_title_or_url')
    return dict(title=title, url=url, price=amount, currency=currency, price_eur=eur,
                condition='unknown', availability='unknown', shipping_eur=None,
                delivery_bg='unknown', price_kind='offer', merchant='', **extra)


def olx(root, base):
    rows = []
    cards = root.xpath('//*[@data-cy="l-card" or @data-testid="l-card"]')
    for card in cards:
        a = first(card, './/a[@data-testid="card-title-link"]')
        if a is None:
            a = first(card, './/a[.//h4 or .//h6]')
        p = first(card, './/*[@data-testid="ad-price"]')
        if a is None or p is None:
            continue
        try:
            r = listing(text(a), urljoin(base, a.get('href', '')), text(p))
        except ValueError:
            continue
        badges = ' '.join(card.xpath('.//*[@data-nx-name="NexusBadge"]//text()')).lower()
        r['condition'] = 'used' if 'използвано' in badges else 'new' if re.search(r'\bново\b', badges) else 'unknown'
        r['availability'] = 'listed'
        r['price_kind'] = 'asking'
        r['delivery_bg'] = 'confirmed' if card.xpath('.//*[@data-testid="card-delivery-badge"]') else 'seller_confirmation'
        seller = first(card, './/a[@data-testid="listing-seller-profile-link"]')
        # Public shop identity only; no personal names, locations or contact details.
        r['merchant'] = canonical(urljoin(base, seller.get('href', ''))) if seller is not None else ''
        rows.append(r)
    return rows, len(cards)


def desktop(root, base):
    cards = root.xpath('//article[.//*[@itemprop="price"]]')
    rows = []
    for card in cards:
        a = first(card, './/a[@href][.//*[@itemprop="name"]]')
        if a is None:
            continue
        try:
            r = listing(prop(card, 'name'), urljoin(base, a.get('href')), prop(card, 'price'), prop(card, 'priceCurrency'))
        except ValueError:
            continue
        cond = prop(card, 'itemCondition').lower()
        r['condition'] = 'new' if 'newcondition' in cond else 'used' if 'usedcondition' in cond else 'refurbished' if 'refurbishedcondition' in cond else 'unknown'
        r['availability'] = availability(prop(card, 'availability'))
        r['delivery_bg'] = 'confirmed'
        r['merchant'] = 'desktop.bg'
        r['sku'] = prop(card, 'sku')
        # Courier cost depends on destination/order; never silently call it free.
        rows.append(r)
    return rows, len(cards)


def json_products(root, base):
    """Schema.org Product/Offer support for product URLs and catalogue JSON-LD."""
    rows, recognized = [], 0

    def walk(value):
        nonlocal recognized
        if isinstance(value, list):
            for v in value:
                walk(v)
        elif isinstance(value, dict):
            types = value.get('@type', [])
            types = [types] if isinstance(types, str) else types
            if 'Product' in types:
                recognized += 1
                offers = value.get('offers', [])
                offers = offers if isinstance(offers, list) else [offers]
                for offer in offers:
                    try:
                        price = offer.get('price', offer.get('lowPrice'))
                        r = listing(value.get('name', ''), urljoin(base, offer.get('url') or value.get('url') or base), price, offer.get('priceCurrency'))
                    except (ValueError, TypeError):
                        continue
                    r['price_kind'] = 'aggregate_from' if 'lowPrice' in offer and 'price' not in offer else 'offer'
                    r['availability'] = availability(offer.get('availability', ''))
                    cond = offer.get('itemCondition', '').lower()
                    r['condition'] = 'new' if 'newcondition' in cond else 'used' if 'usedcondition' in cond else 'unknown'
                    seller = offer.get('seller', {})
                    r['merchant'] = seller.get('name', '') if isinstance(seller, dict) else str(seller)
                    r['sku'] = value.get('mpn') or value.get('sku', '')
                    rows.append(r)
            for k, v in value.items():
                if k != 'offers':
                    walk(v)
    for script in root.xpath('//script[@type="application/ld+json"]'):
        try:
            walk(json.loads(script.text or ''))
        except (ValueError, TypeError):
            continue
    return rows, recognized


def parse(source, content, base):
    root = html.fromstring(content)
    visible = ' '.join(root.xpath('//body//text()[not(ancestor::script) and not(ancestor::style)]')).lower()
    if any(s in visible for s in ('verify you are human', 'performing security verification', 'проверка за сигурността на връзката', 'checking your browser', 'just a moment...')):
        raise ValueError('security_challenge')
    if source == 'olx':
        rows, count = olx(root, base)
    elif source == 'desktop':
        rows, count = desktop(root, base)
    else:
        rows, count = json_products(root, base)
    # No parsed cards is a parser/source failure unless the page explicitly says zero results.
    empty = bool(re.search(r'(открихме\s+0\s+обяви|0\s+резултата|няма намерени|не намерихме)', visible))
    if not rows and not empty:
        raise ValueError('parser_no_prices' if count else 'parser_no_cards')
    next_links = root.xpath('//a[@rel="next"]/@href | //a[@data-testid="pagination-forward"]/@href')
    return rows, urljoin(base, next_links[0]) if next_links else None
