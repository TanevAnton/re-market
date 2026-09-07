import re
import unicodedata


def norm(value):
    s = unicodedata.normalize('NFKC', value.replace('™', '').replace('®', '')).lower()
    s = re.sub(r'(?<=\d)\s*(gb|tb|гб|тб)\b', lambda m: {'гб':'gb','тб':'tb'}.get(m[1],m[1]), s)
    return re.sub(r'\s+', ' ', s).strip()


GPU = re.compile(r'\b(rtx|rx|gtx|arc)\s*[- ]?\s*([ab]?\d{3,4})\s*(ti\s*super|super|ti|xtx|xt|gre)?\b', re.I)
CPU = re.compile(r'\b(?:ryzen\s*[3579]\s*|(?:core\s*)?i[3579]\s*[- ]\s*|core\s*ultra\s*[3579]\s*)(\d{3,5}(?:x3d|xt|kf|ks|f|k|x|g|t)?)(?![a-z0-9])', re.I)
BAD = re.compile(r'\b(за части|неработещ\w*|повреден\w*|дефект\w*|изкупув\w*|купувам|търся|broken|faulty|wanted|repair|waterblock|воден блок|радиатор за|кутия от|само кутия|empty box)\b', re.I)
PC = re.compile(r'\b(компютър\w*|компютри|конфигураци\w*|лаптоп\w*|laptop|notebook|gaming pc|desktop pc|mini pc|workstation|rig|grigs)\b', re.I)


def match(row, model):
    title = norm(row['title'])
    if BAD.search(title):
        return 'rejected', 'parts_wanted_or_faulty'
    if PC.search(title) or (GPU.search(title) and CPU.search(title)):
        return 'rejected', 'complete_system_or_bundle'
    if re.search(r'\b\d+\s*(?:бр\.?|pieces|pcs)\b|\b(?:bundle|лот|комплект с)\b', title):
        return 'review', 'possible_bundle'
    category = model['category']
    if category == 'gpu':
        found = list(GPU.finditer(title))
        expected = GPU.search(model['name'])
        key = lambda m: (m[1].lower(),m[2].lower(),re.sub(r'\s+','',m[3] or '').lower())
        if not expected or not found or key(expected) not in [key(m) for m in found]:
            return 'rejected', 'different_gpu'
        if len({key(m) for m in found}) != 1:
            return 'review', 'multiple_models'
        sizes = set(re.findall(r'\b(\d+)gb\b', title))
        wanted = str(model.get('vram_gb', ''))
        if wanted and sizes != {wanted}:
            return ('review', 'missing_vram') if not sizes else ('rejected', 'different_vram')
        if re.search(r'\b(fan|вентилатор\w*|охладител\w*|кабел\w*|кабели|cable|backplate|adapter)\b', title):
            return 'rejected', 'accessory'
    elif category == 'cpu':
        found = list(CPU.finditer(title))
        expected = CPU.search(model['name'])
        if not expected or len(found) != 1 or found[0][1].lower() != expected[1].lower():
            return 'rejected', 'different_cpu'
        if re.search(r'\b(дън\w*|motherboard|охладител\w*|cooler)\b', title):
            return 'review', 'possible_bundle'
    else:
        if not all(re.search(p, title, re.I) for p in model['required']):
            return 'rejected', 'different_specification'
    if any(re.search(p, title, re.I) for p in model.get('exclude', [])):
        return 'rejected', 'excluded_variant'
    if row['price_eur'] < model.get('min_eur', 2) or row['price_eur'] > model.get('max_eur', 10000):
        return 'review', 'implausible_price'
    if row.get('condition') not in ('new', 'used'):
        return 'review', 'condition_unknown'
    if row.get('availability') not in ('in_stock', 'listed'):
        return 'review', 'availability_unconfirmed'
    if row.get('price_kind') == 'aggregate_from':
        return 'review', 'aggregate_not_independent_offer'
    if row.get('delivery_bg') != 'confirmed':
        return 'review', 'delivery_to_bulgaria_unconfirmed'
    return 'accepted', 'model_match' if category in ('gpu','cpu') else 'spec_match'
