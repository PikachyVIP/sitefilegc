"""Domain-based authentication configuration for outbound downloads.

Loads a JSON config that maps domains (host[:port]) to authentication
credentials so downloaders can attach the right auth when fetching files
from known origins.

Config format (``domain_auth/config.json``):

    [
      {
        "domain": "files.example.com",
        "auth_type": "basic",
        "secret": "username:password"
      },
      {
        "domain": "api.example.com:8443",
        "auth_type": "bearer",
        "secret": "eyJhbGciOi..."
      },
      {
        "domain": "internal.example.com",
        "auth_type": "header",
        "secret": "X-Api-Key: abc123"
      }
    ]

Recognized ``auth_type`` values (case-insensitive):
- ``basic`` / ``username and password`` / ``username_password``
  -> HTTP Basic auth, ``secret`` is ``"user:password"``.
- ``bearer`` / ``bearer auth`` / ``bearer token`` / ``token``
  -> ``Authorization: Bearer <secret>`` header.
- ``header`` / ``custom header``
  -> ``secret`` is a literal header line ``"Header-Name: value"``.
"""

from __future__ import annotations

import json
import logging
from dataclasses import dataclass
from functools import lru_cache
from pathlib import Path
from typing import Iterable
from urllib.parse import urlparse

import httpx

from .settings import get_settings

logger = logging.getLogger(__name__)


_BASIC_ALIASES = {"basic", "username and password", "username_password", "user_pass", "userpass"}
_BEARER_ALIASES = {"bearer", "bearer auth", "bearer token", "token"}
_HEADER_ALIASES = {"header", "custom header", "custom_header"}


@dataclass
class DomainAuth:
    """Resolved authentication material for a single domain entry."""

    domain: str
    auth: httpx.Auth | None = None
    headers: dict[str, str] | None = None


def _normalize_domain(value: str) -> str:
    return value.strip().lower().lstrip(".")


def _url_domain(url: str) -> str | None:
    parsed = urlparse(url)
    host = parsed.hostname
    if not host:
        return None
    domain = host.lower()
    if parsed.port:
        domain = f"{domain}:{parsed.port}"
    return domain


def _build_entry(raw: dict) -> DomainAuth | None:
    domain = raw.get("domain")
    auth_type = raw.get("auth_type") or raw.get("type")
    secret = raw.get("secret")
    if not domain or not auth_type or secret is None:
        logger.warning("Skipping invalid domain_auth entry: %r", raw)
        return None

    normalized_type = str(auth_type).strip().lower()
    secret_str = str(secret)

    if normalized_type in _BASIC_ALIASES:
        if ":" not in secret_str:
            logger.warning(
                "Skipping basic auth entry for %s: secret must be 'user:password'", domain
            )
            return None
        username, password = secret_str.split(":", 1)
        return DomainAuth(
            domain=_normalize_domain(domain),
            auth=httpx.BasicAuth(username, password),
        )

    if normalized_type in _BEARER_ALIASES:
        return DomainAuth(
            domain=_normalize_domain(domain),
            headers={"Authorization": f"Bearer {secret_str.strip()}"},
        )

    if normalized_type in _HEADER_ALIASES:
        if ":" not in secret_str:
            logger.warning(
                "Skipping header auth entry for %s: secret must be 'Name: Value'", domain
            )
            return None
        name, value = secret_str.split(":", 1)
        return DomainAuth(
            domain=_normalize_domain(domain),
            headers={name.strip(): value.strip()},
        )

    logger.warning("Skipping domain_auth entry for %s: unknown auth_type %r", domain, auth_type)
    return None


def _load_entries(path: Path) -> list[DomainAuth]:
    if not path.exists():
        return []
    try:
        raw_data = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError) as exc:
        logger.warning("Failed to read domain_auth config at %s: %s", path, exc)
        return []

    if not isinstance(raw_data, Iterable) or isinstance(raw_data, (str, bytes, dict)):
        logger.warning("domain_auth config must be a JSON array, got %s", type(raw_data).__name__)
        return []

    entries: list[DomainAuth] = []
    for item in raw_data:
        if not isinstance(item, dict):
            logger.warning("Skipping non-object entry in domain_auth config: %r", item)
            continue
        entry = _build_entry(item)
        if entry is not None:
            entries.append(entry)
    return entries


def _config_version(path: Path) -> int | None:
    try:
        return path.stat().st_mtime_ns
    except OSError:
        return None


@lru_cache(maxsize=8)
def _cached_entries(path_str: str, version: int | None) -> tuple[DomainAuth, ...]:
    return tuple(_load_entries(Path(path_str)))


def get_domain_auth_for_url(url: str) -> DomainAuth | None:
    """Return the configured auth entry matching the URL's host[:port], if any."""
    domain = _url_domain(url)
    if not domain:
        return None

    settings = get_settings()
    config_path = settings.domain_auth_config_path
    entries = _cached_entries(str(config_path), _config_version(config_path))
    if not entries:
        return None

    # Prefer an exact host:port match, then fall back to a host-only match.
    host_only = domain.split(":", 1)[0]
    exact: DomainAuth | None = None
    host_match: DomainAuth | None = None
    for entry in entries:
        if entry.domain == domain:
            exact = entry
            break
        if entry.domain == host_only and host_match is None:
            host_match = entry
    return exact or host_match


def reload_domain_auth_cache() -> None:
    """Clear the cached domain_auth entries (useful for tests / config reloads)."""
    _cached_entries.cache_clear()
