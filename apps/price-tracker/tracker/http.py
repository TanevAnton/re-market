"""Bounded public HTTP client with robots policy, host allowlist and circuit breaker."""
import time
import urllib.error
import urllib.request
import urllib.robotparser
from urllib.parse import urlsplit

AGENT = 'ReTechPriceTracker/1.0 (+https://github.com/TanevAnton/re-market)'


class SourceError(RuntimeError):
    pass


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None


class Client:
    def __init__(self, hosts, delay=3.0, timeout=20):
        self.hosts = set(hosts)
        self.delay = max(3.0, delay)
        self.timeout = timeout
        self.robots = {}
        self.last = {}
        self.opener = urllib.request.build_opener(NoRedirect)
        self.cache = {}

    def validate(self, url):
        p = urlsplit(url)
        if p.scheme != 'https' or p.hostname not in self.hosts or p.username or p.password or p.port not in (None, 443):
            raise SourceError('url_not_allowlisted')
        return p

    def request(self, url):
        p = self.validate(url)
        time.sleep(max(0, self.delay - (time.monotonic() - self.last.get(p.hostname, 0))))
        self.last[p.hostname] = time.monotonic()
        req = urllib.request.Request(url, headers={'User-Agent': AGENT, 'Accept': 'text/html,text/plain', 'Accept-Language': 'bg,en;q=0.5'})
        try:
            with self.opener.open(req, timeout=self.timeout) as r:
                data = r.read(8_000_001)
                if len(data) > 8_000_000:
                    raise SourceError('response_too_large')
                return data.decode(r.headers.get_content_charset() or 'utf-8', errors='replace')
        except urllib.error.HTTPError:
            raise
        except (OSError, TimeoutError) as exc:
            raise SourceError(type(exc).__name__) from exc

    def get(self, url):
        p = self.validate(url)
        if url in self.cache:
            return self.cache[url]
        if p.hostname not in self.robots:
            robot_url = f'https://{p.netloc}/robots.txt'
            robot = urllib.robotparser.RobotFileParser(robot_url)
            try:
                body = self.request(robot_url)
                if '<html' in body.lower() or '<!doctype' in body.lower():
                    raise SourceError('robots_unavailable')
                robot.parse(body.splitlines())
            except urllib.error.HTTPError as exc:
                if exc.code == 404:
                    robot.parse([])
                else:
                    raise SourceError(f'robots_http_{exc.code}') from exc
            self.robots[p.hostname] = robot
        robot = self.robots[p.hostname]
        if not robot.can_fetch(AGENT, url):
            raise SourceError('robots_disallowed')
        self.delay = max(self.delay, robot.crawl_delay(AGENT) or 0)
        try:
            body = self.request(url)
        except urllib.error.HTTPError as exc:
            # Do not retry a rejection, challenge, rate limit, or redirect automatically.
            raise SourceError(f'http_{exc.code}') from exc
        self.cache[url] = body
        return body
