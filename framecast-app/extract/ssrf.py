"""
SSRF guard.

This service fetches arbitrary user-supplied URLs from inside the compose
network, so it must defend itself: without this, "import from a URL" is a
request forwarder that can reach Postgres, Redis, the API, or the cloud
metadata endpoint.

Ported from the Node renderer's guard (renderer/server.js). Kept as its own
module with its own tests because it is the security-critical part of the
service, not an implementation detail of the fetcher.
"""

from __future__ import annotations

import ipaddress
import socket
from urllib.parse import urlparse


class BlockedUrl(Exception):
    """Raised when a URL must not be fetched."""


def _is_private(ip: str) -> bool:
    try:
        addr = ipaddress.ip_address(ip)
    except ValueError:
        # Unparseable means we can't prove it's safe.
        return True

    return (
        addr.is_private
        or addr.is_loopback
        or addr.is_link_local      # includes 169.254.169.254 cloud metadata
        or addr.is_reserved
        or addr.is_multicast
        or addr.is_unspecified
    )


def assert_fetchable(url: str) -> str:
    """
    Validate a URL and confirm every address it resolves to is public.

    Resolution matters: a hostname under the caller's control can point at
    127.0.0.1 or 169.254.169.254, so checking the literal string is not enough.
    All resolved addresses are checked, because a name can return several and
    the connection may use any of them.
    """
    parsed = urlparse(url)

    if parsed.scheme not in ("http", "https"):
        raise BlockedUrl("Only http and https URLs can be imported.")

    host = parsed.hostname
    if not host:
        raise BlockedUrl("That URL has no host.")

    try:
        infos = socket.getaddrinfo(host, None)
    except socket.gaierror as exc:
        raise BlockedUrl(f"Could not resolve {host}.") from exc

    for info in infos:
        ip = info[4][0]
        if _is_private(ip):
            raise BlockedUrl("That address is not publicly reachable.")

    return url
