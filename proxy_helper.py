"""
Indian proxy fetcher — ProxyScrape API + public lists se fresh proxies.
"""

import asyncio
import logging
import httpx

logger = logging.getLogger(__name__)

TEST_URL = "https://myaadhaar.uidai.gov.in/"

# ProxyScrape API — India filtered directly
PROXYSCRAPE_IN = (
    "https://api.proxyscrape.com/v3/free-proxy-list/get"
    "?request=displayproxies&country=in&protocol=http&proxy_format=ipport&format=text&timeout=5000"
)
PROXYSCRAPE_ALL = (
    "https://api.proxyscrape.com/v3/free-proxy-list/get"
    "?request=displayproxies&protocol=http&proxy_format=ipport&format=text&timeout=5000&limit=200"
)
GEONODE_IN = (
    "https://proxylist.geonode.com/api/proxy-list"
    "?limit=50&page=1&sort_by=lastChecked&sort_type=desc&country=IN&protocols=http"
)

# Fallback raw lists
RAW_LISTS = [
    "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
    "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt",
    "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt",
]

# Indian IP prefixes
IN_PREFIXES = (
    "1.22.", "1.23.", "14.", "27.", "43.", "49.", "59.", "61.",
    "103.", "106.", "110.", "111.", "112.", "115.", "116.", "117.",
    "119.", "120.", "121.", "122.", "123.", "124.", "125.", "182.",
    "183.", "202.", "203.", "210.", "211.", "220.", "221.",
)


async def _fetch_text(client: httpx.AsyncClient, url: str) -> list[str]:
    try:
        r = await client.get(url, timeout=15)
        if r.status_code == 200:
            return [l.strip() for l in r.text.splitlines() if ":" in l and not l.startswith("#")]
    except Exception as e:
        logger.debug(f"Fetch fail {url}: {e}")
    return []


async def _fetch_geonode(client: httpx.AsyncClient) -> list[str]:
    try:
        r = await client.get(GEONODE_IN, timeout=15)
        if r.status_code == 200:
            data = r.json()
            return [f"{p['ip']}:{p['port']}" for p in data.get("data", [])]
    except Exception as e:
        logger.debug(f"Geonode fail: {e}")
    return []


async def _test_proxy(proxy_str: str, timeout: int = 10) -> bool:
    try:
        async with httpx.AsyncClient(
            proxy=f"http://{proxy_str}",
            timeout=timeout,
            verify=False,
            follow_redirects=True,
        ) as c:
            r = await c.get(TEST_URL)
            if r.status_code < 500:
                logger.info(f"✅ Working proxy: {proxy_str} → HTTP {r.status_code}")
                return True
    except Exception as e:
        logger.debug(f"❌ {proxy_str} → {type(e).__name__}")
    return False


async def get_working_indian_proxy(max_test: int = 60) -> str | None:
    logger.info("🔍 Indian proxy fetch ho rahi hai...")
    async with httpx.AsyncClient(timeout=15, verify=False, follow_redirects=True) as client:
        tasks = [
            _fetch_text(client, PROXYSCRAPE_IN),
            _fetch_text(client, PROXYSCRAPE_ALL),
            _fetch_geonode(client),
            *[_fetch_text(client, u) for u in RAW_LISTS],
        ]
        results = await asyncio.gather(*tasks, return_exceptions=True)

    all_proxies: list[str] = []
    for r in results:
        if isinstance(r, list):
            all_proxies.extend(r)

    # Deduplicate
    all_proxies = list(dict.fromkeys(all_proxies))

    # Split: Indian first
    indian = [p for p in all_proxies if p.startswith(IN_PREFIXES)]
    others = [p for p in all_proxies if not p.startswith(IN_PREFIXES)]

    to_test = (indian + others)[:max_test]
    logger.info(f"Total proxies: {len(all_proxies)} | Indian: {len(indian)} | Testing: {len(to_test)}")

    sem = asyncio.Semaphore(15)

    async def guarded_test(p):
        async with sem:
            return p, await _test_proxy(p)

    test_results = await asyncio.gather(*[guarded_test(p) for p in to_test])

    # Prefer Indian
    for proxy, ok in test_results:
        if ok and proxy.startswith(IN_PREFIXES):
            return proxy
    # Any working
    for proxy, ok in test_results:
        if ok:
            return proxy

    logger.warning("⚠️ Koi working proxy nahi mila.")
    return None
