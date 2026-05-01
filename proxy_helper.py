"""
Free Indian proxy fetcher — scrapes public proxy lists and tests against UIDAI.
"""

import asyncio
import logging
import httpx

logger = logging.getLogger(__name__)

TEST_URL = "https://myaadhaar.uidai.gov.in/retrieve-eid-uid"

# Public proxy list sources (free, no signup)
PROXY_SOURCES = [
    "https://raw.githubusercontent.com/TheSpeedX/PROXY-List/master/http.txt",
    "https://raw.githubusercontent.com/clarketm/proxy-list/master/proxy-list-raw.txt",
    "https://raw.githubusercontent.com/ShiftyTR/Proxy-List/master/http.txt",
    "https://raw.githubusercontent.com/monosans/proxy-list/main/proxies/http.txt",
]

# Known Indian proxy ranges (approximate) — filter by these
IN_RANGES = [
    "103.", "106.", "110.", "111.", "112.", "115.", "116.", "117.",
    "120.", "122.", "123.", "124.", "125.", "27.", "49.", "59.",
    "61.", "14.", "1.22", "1.23", "117.196", "117.197",
    "103.21", "103.22", "103.23", "103.24", "103.25",
]


async def _fetch_proxy_list() -> list[str]:
    """Download proxy lists from multiple sources."""
    proxies = set()
    async with httpx.AsyncClient(timeout=15, follow_redirects=True) as client:
        for src in PROXY_SOURCES:
            try:
                r = await client.get(src)
                if r.status_code == 200:
                    lines = r.text.strip().splitlines()
                    for line in lines:
                        line = line.strip()
                        if ":" in line and not line.startswith("#"):
                            proxies.add(line)
            except Exception as e:
                logger.debug(f"Proxy source {src} failed: {e}")
    return list(proxies)


def _is_indian_proxy(proxy: str) -> bool:
    """Best-effort filter for Indian IP ranges."""
    for prefix in IN_RANGES:
        if proxy.startswith(prefix):
            return True
    return False


async def _test_proxy(proxy_str: str, timeout: int = 8) -> bool:
    """Check if proxy can reach UIDAI."""
    proxy_url = f"http://{proxy_str}"
    try:
        async with httpx.AsyncClient(
            proxy=proxy_url,
            timeout=timeout,
            verify=False,
            follow_redirects=True,
        ) as client:
            r = await client.get(TEST_URL)
            ok = r.status_code < 500
            if ok:
                logger.info(f"✅ Proxy working: {proxy_str} → HTTP {r.status_code}")
            return ok
    except Exception as e:
        logger.debug(f"❌ Proxy fail: {proxy_str} → {e}")
        return False


async def get_working_indian_proxy(max_test: int = 40) -> str | None:
    """
    Fetch proxy lists, filter Indian IPs, test concurrently, return first working one.
    Returns proxy string like "103.x.x.x:port" or None if none found.
    """
    logger.info("Proxy list fetch ho rahi hai...")
    all_proxies = await _fetch_proxy_list()
    logger.info(f"Total proxies mili: {len(all_proxies)}")

    indian = [p for p in all_proxies if _is_indian_proxy(p)]
    other  = [p for p in all_proxies if not _is_indian_proxy(p)]

    # Try Indian first, then others
    to_test = (indian + other)[:max_test]
    logger.info(f"Testing {len(to_test)} proxies ({len(indian)} Indian)...")

    sem = asyncio.Semaphore(10)

    async def test_one(proxy: str) -> tuple[str, bool]:
        async with sem:
            ok = await _test_proxy(proxy)
            return proxy, ok

    results = await asyncio.gather(*[test_one(p) for p in to_test])

    # Prefer Indian proxies
    for proxy, ok in results:
        if ok and _is_indian_proxy(proxy):
            logger.info(f"Working Indian proxy mila: {proxy}")
            return proxy

    # Fallback to any working
    for proxy, ok in results:
        if ok:
            logger.info(f"Working proxy mila (non-Indian): {proxy}")
            return proxy

    logger.warning("Koi working proxy nahi mila.")
    return None
