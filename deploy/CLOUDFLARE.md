# Cloudflare in front of the site

What it buys: the photographs and the CSS/JS are served from Cloudflare's
network instead of from a home connection, the origin address stops being the
thing every visitor (and every attacker) connects to, and a traffic spike lands
on their servers rather than on the router.

What it risks, in order of how badly it goes wrong:

1. **Every visitor looks like the same handful of addresses.** Rate limiting on
   login, registration and offers is per client address. Behind Cloudflare the
   address Laravel sees is Cloudflare's unless the real one is restored first.
   Step 4 is not optional.
2. **Rocket Loader and email obfuscation rewrite the page's HTML/JS.** Livewire
   and Alpine break in confusing ways. Both off (step 6).
3. **Caching a page that is different per person.** Cloudflare does not cache
   HTML by default, and nothing below changes that. Do not add a "cache
   everything" rule for the whole site.

Reversible at any time: set the records back to *DNS only* (grey cloud) and
traffic goes straight to the origin again within a minute or two.

---

## 1. Add the domain

Cloudflare dashboard → *Add a site* → `rigo.bg` → Free plan. It lists the DNS
records it found; compare them with what you have now before continuing. Then
change the nameservers at the registrar to the two Cloudflare gives you.
Nothing changes for visitors until that propagates.

## 2. DNS

| Name | Type | Target | Proxy |
|---|---|---|---|
| `@` and `www` | A | the public IP of the router | **Proxied** (orange) |
| mail (`MX`, `mail`, anything SMTP/IMAP) | | | **DNS only** (grey) |
| SPF / DKIM / DMARC `TXT` | | | not proxied (TXT never is) |

Mail records must stay grey. Cloudflare's proxy carries web traffic only.

## 3. TLS

*SSL/TLS → Overview* → **Full (strict)**. That needs a certificate on Apache
that Cloudflare trusts: *SSL/TLS → Origin Server → Create certificate* (15
years), install it in Apache, done. Do **not** pick *Flexible*: it talks plain
HTTP to the origin and produces a redirect loop with Laravel's HTTPS handling.

*Edge Certificates*: Always Use HTTPS **on**, Minimum TLS 1.2. Leave HSTS off
until everything has worked for a week.

## 4. Real visitor addresses (Apache)

The chain is Cloudflare → router → Apache (`192.168.1.77`) → nginx → PHP.
`bootstrap/app.php` trusts exactly one proxy, the Apache box, and reads the
client address from the `X-Forwarded-For` Apache sends. Apache therefore has to
put the *visitor* in that header rather than Cloudflare's edge.

```
sudo a2enmod remoteip
```

In the Apache virtual host for the site:

```
RemoteIPHeader CF-Connecting-IP
# Cloudflare's published ranges - https://www.cloudflare.com/ips-v4 and /ips-v6
# One line per range:
RemoteIPTrustedProxy 173.245.48.0/20
RemoteIPTrustedProxy 103.21.244.0/22
# ... the rest of the list ...
```

Fetch the current list from those two URLs when you do this; it changes
occasionally. Then `sudo apachectl configtest && sudo systemctl reload apache2`.

**Check it before moving on.** Put `%a` (not `%h`) in Apache's `LogFormat`, load
the site from your phone on mobile data, and read the last line of the access
log: it must be the phone's address, not one in a Cloudflare range. If it is a
Cloudflare address the header is not being honoured and every rate limit on the
site is currently shared by all visitors.

## 5. Cache rules

*Caching → Cache Rules*. Cloudflare already caches by file extension (images,
CSS, JS), which covers the `/storage/…jpg`, `/storage/…webp` and `/build/…`
files. Two additions:

| If the URL path… | Then |
|---|---|
| starts with `/build/` | Eligible for cache; Edge TTL 1 year (files are content-hashed) |
| ends with `/snimka` | Eligible for cache; Edge TTL **respect origin** (the cards send `max-age=86400` and their address changes when the price does) |

Nothing else. In particular nothing for `/livewire`, `/admin`, `/profil`, or
any listing page.

## 6. Speed settings

*Speed → Optimization*: Brotli **on**, HTTP/3 **on**, Early Hints on.
**Rocket Loader: OFF.** *Scrape Shield*: Email Address Obfuscation **OFF**.
Auto-minify is gone in current Cloudflare; the Vite build already minifies.

Skip *Polish* and *Mirage*: the site writes its own WebP copies of photographs
(`php artisan images:webp` for the old ones; `deploy.sh` runs it).

## 7. What not to turn on (yet)

- **Bot Fight Mode / Super Bot Fight Mode**: can challenge Facebook's and
  Viber's link-preview fetchers and search-engine crawlers - which would put
  the share cards back to blank and cost indexing. If you want it, switch it
  on, then run a listing through the Facebook Sharing Debugger and Search
  Console's URL inspection.
- **Under Attack Mode**: only while under attack, and off again afterwards.
- **Any WAF rule that blocks by country**: the buyers and the couriers are in
  Bulgaria but the people who share links are everywhere.

## 8. Upload limits

Cloudflare's Free plan allows 100 MB per request and gives the origin 100
seconds to answer. The site allows 64 MB (nginx and PHP) so size is fine, but a
batch of twelve phone photos on a slow uplink can run past 100 seconds and the
seller sees a *524*. nginx and PHP wait 120 s; Cloudflare gives up first. If
sellers report it, the answer is smaller batches, not a setting.

## 9. Lock the origin (optional, later)

Once everything works, allow ports 80/443 on the router **only** from
Cloudflare's ranges, so the home address cannot be used to bypass it. Do this
last, and keep a way in that does not depend on it.

## 10. After switching on

1. `curl -sI https://rigo.bg/` → expect `server: cloudflare` and a `cf-ray` header.
2. Load a listing with photographs; in the browser's network tab a `.webp`
   photo should show `cf-cache-status: HIT` on the second load.
3. Log in, log out, post a test listing: nothing should behave differently.
4. Facebook Sharing Debugger on a listing URL: the card must render.
5. *Caching → Configuration → Purge Everything* once, after the first deploy
   behind it.
6. Watch the Apache access log for a day: addresses must be visitors', not
   Cloudflare's (step 4).
