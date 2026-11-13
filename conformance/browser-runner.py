#!/usr/bin/env python3
"""
Drive OpenID Foundation front-channel URLs against Signaculum with Selenium.

The suite exposes browser URLs through its runner API; HtmlUnit is not enough for
the Inertia login and consent pages, so this worker visits them in Chromium.
"""

from __future__ import annotations

import os
import sys
import time
import urllib.parse

import httpx
from selenium import webdriver
from selenium.common.exceptions import NoSuchElementException
from selenium.common.exceptions import StaleElementReferenceException
from selenium.webdriver import ChromeOptions
from selenium.webdriver.common.by import By

CONFORMANCE_SERVER = os.environ.get("CONFORMANCE_SERVER", "https://nginx:8443/").rstrip("/")
SELENIUM_REMOTE_URL = (
    os.environ.get("SELENIUM_REMOTE_URL")
    or os.environ.get("SELENIUM_HUB_HOST")
    or "http://chrome:4444/wd/hub"
)
OIDC_TEST_USER = os.environ["OIDC_TEST_USER"]
OIDC_TEST_PASSWORD = os.environ["OIDC_TEST_PASSWORD"]
POLL_SECONDS = float(os.environ.get("CONFORMANCE_BROWSER_POLL_SECONDS", "1"))
VISIT_TIMEOUT_SECONDS = int(os.environ.get("CONFORMANCE_BROWSER_VISIT_TIMEOUT", "90"))
LOGIN_REDIRECT_TIMEOUT_SECONDS = int(os.environ.get("CONFORMANCE_BROWSER_LOGIN_REDIRECT_TIMEOUT", "60"))
OP_HOST = urllib.parse.urlsplit(
    os.environ.get("SIGNACULUM_BASE_URL", "https://signaculum-proxy:8443")
).hostname
SUITE_HOST = urllib.parse.urlsplit(CONFORMANCE_SERVER).hostname


def log(message: str) -> None:
    print(f"[conformance-browser] {message}", flush=True)


def describe_url(url: str) -> str:
    parsed = urllib.parse.urlsplit(url)
    origin = "op" if parsed.hostname == OP_HOST else "suite" if parsed.hostname == SUITE_HOST else "other"
    parts = [part for part in parsed.path.split("/") if part]

    if origin == "op":
        parts = parts[:4]
    elif origin == "suite":
        parts = parts[:2]
    else:
        parts = []

    return f"{origin} /{'/'.join(parts)}"


def new_driver():
    options = ChromeOptions()
    options.set_capability("acceptInsecureCerts", True)

    for argument in (
        "--headless=new",
        "--no-sandbox",
        "--disable-dev-shm-usage",
        "--ignore-certificate-errors",
        "--window-size=1280,1000",
    ):
        options.add_argument(argument)

    return webdriver.Remote(command_executor=SELENIUM_REMOTE_URL, options=options)


def first_present(driver, selectors, timeout=5):
    end = time.monotonic() + timeout

    while time.monotonic() < end:
        for by, value in selectors:
            try:
                element = driver.find_element(by, value)

                if element.is_displayed():
                    return element
            except (NoSuchElementException, StaleElementReferenceException):
                pass

        time.sleep(0.2)

    raise NoSuchElementException(f"None of the selectors were found: {selectors}")


def first_clickable(driver, selectors, timeout=5):
    end = time.monotonic() + timeout

    while time.monotonic() < end:
        for by, value in selectors:
            try:
                element = driver.find_element(by, value)

                if element.is_displayed() and element.is_enabled():
                    return element
            except (NoSuchElementException, StaleElementReferenceException):
                pass

        time.sleep(0.2)

    raise NoSuchElementException(f"None of the selectors were clickable: {selectors}")


def submit_post(driver, url: str) -> None:
    parsed = urllib.parse.urlsplit(url)
    action = urllib.parse.urlunsplit((parsed.scheme, parsed.netloc, parsed.path, "", ""))
    params = urllib.parse.parse_qsl(parsed.query, keep_blank_values=True)
    inputs = "\n".join(
        f'<input type="hidden" name="{html_escape(name)}" value="{html_escape(value)}" />'
        for name, value in params
    )
    page = f"""<!doctype html><html><body>
<form id="post-form" method="post" action="{html_escape(action)}">{inputs}</form>
<script>document.getElementById('post-form').submit();</script>
</body></html>"""
    driver.get("data:text/html;charset=utf-8," + urllib.parse.quote(page))


def html_escape(value: str) -> str:
    return (
        value.replace("&", "&amp;")
        .replace('"', "&quot;")
        .replace("<", "&lt;")
        .replace(">", "&gt;")
    )


def is_login_page(driver) -> bool:
    parsed = urllib.parse.urlsplit(driver.current_url)

    if parsed.hostname != OP_HOST:
        return False

    path = parsed.path.rstrip("/")

    return path in ("/login", "") or path.endswith("/login")


def is_consent_page(driver) -> bool:
    if OP_HOST and urllib.parse.urlsplit(driver.current_url).hostname != OP_HOST:
        return False

    return bool(driver.find_elements(By.CSS_SELECTOR, '[data-test="approve-authorization"]'))


def is_logout_confirm_page(driver) -> bool:
    parsed = urllib.parse.urlsplit(driver.current_url)

    if parsed.hostname != OP_HOST:
        return False

    path = parsed.path.rstrip("/")

    return "end-session" in path or path.endswith("/logout") or bool(
        driver.find_elements(By.CSS_SELECTOR, 'button[type="submit"]')
    ) and "oauth" in path


def login(driver) -> bool:
    log(f"Logging in at {describe_url(driver.current_url)}")

    for _ in range(4):
        if not is_login_page(driver):
            return False

        submit_attempted = False

        try:
            email = first_present(driver, ((By.ID, "email"), (By.NAME, "email")), timeout=10)
            email.clear()
            email.send_keys(OIDC_TEST_USER)
            password = first_present(driver, ((By.ID, "password"), (By.NAME, "password")), timeout=10)
            password.clear()
            password.send_keys(OIDC_TEST_PASSWORD)
            submit = first_clickable(
                driver,
                (
                    (By.CSS_SELECTOR, 'button[type="submit"]'),
                    (By.CSS_SELECTOR, 'input[type="submit"]'),
                ),
                timeout=10,
            )
            submit_attempted = True
            submit.click()
            break
        except (StaleElementReferenceException, NoSuchElementException):
            if not is_login_page(driver):
                return False

            if submit_attempted:
                break

            time.sleep(0.2)
    else:
        raise RuntimeError("Signaculum login form did not stabilize")

    if not wait_for_login_redirect(driver):
        raise RuntimeError("Login was submitted, but the browser remained on the login page")

    return True


def wait_for_login_redirect(driver) -> bool:
    deadline = time.monotonic() + LOGIN_REDIRECT_TIMEOUT_SECONDS

    while time.monotonic() < deadline:
        if not is_login_page(driver):
            return True

        time.sleep(0.2)

    return False


def grant_consent_if_present(driver) -> bool:
    if not is_consent_page(driver):
        return False

    try:
        allow = first_clickable(
            driver,
            ((By.CSS_SELECTOR, '[data-test="approve-authorization"]'),),
            timeout=15,
        )
        log("Granting consent")
        allow.click()

        return True
    except NoSuchElementException:
        return False


def confirm_logout_if_present(driver) -> bool:
    parsed = urllib.parse.urlsplit(driver.current_url)

    if parsed.hostname != OP_HOST:
        return False

    if "end-session" not in parsed.path and "/oauth/" not in parsed.path:
        return False

    try:
        confirm = first_clickable(
            driver,
            ((By.CSS_SELECTOR, 'button[type="submit"]'),),
            timeout=5,
        )
        log("Confirming RP-initiated logout")
        confirm.click()

        return True
    except NoSuchElementException:
        return False


def is_conformance_callback(url: str) -> bool:
    parsed = urllib.parse.urlsplit(url)

    return parsed.netloc in {"nginx:8443", "localhost:8443"} and (
        parsed.path.startswith("/test/a/") or parsed.path.startswith("/test-mtls/a/")
    )


def drive_url(driver, method: str, url: str) -> str:
    log(f"Visiting {method} {describe_url(url)}")

    if method.upper() == "POST":
        submit_post(driver, url)
    else:
        driver.get(url)

    deadline = time.monotonic() + VISIT_TIMEOUT_SECONDS
    last_seen_url = None

    while time.monotonic() < deadline:
        current_url = driver.current_url

        if current_url != last_seen_url:
            last_seen_url = current_url
            log(f"Browser at {describe_url(current_url)}")

        if is_conformance_callback(current_url):
            log(f"Reached conformance callback {describe_url(current_url)}")

            return current_url

        if is_login_page(driver):
            login(driver)
            continue

        if grant_consent_if_present(driver):
            continue

        if confirm_logout_if_present(driver):
            continue

        time.sleep(0.5)

    log(f"Timed out waiting for callback; current page is {describe_url(driver.current_url)}")

    return driver.current_url


def get_browser_items(status: dict) -> list[dict]:
    browser = status.get("browser") or {}
    items = browser.get("urlsWithMethod") or []

    if items:
        return [
            {"url": item["url"], "method": item.get("method") or "GET"}
            for item in items
            if item.get("url")
        ]

    return [{"url": url, "method": "GET"} for url in browser.get("urls", [])]


def get_driver(drivers: dict, test_id: str):
    driver = drivers.get(test_id)

    if driver is None:
        driver = new_driver()
        drivers[test_id] = driver

    return driver


def close_driver(drivers: dict, test_id: str) -> None:
    driver = drivers.pop(test_id, None)

    if driver is None:
        return

    log(f"Closing browser session for {test_id}")

    try:
        driver.quit()
    except Exception as exc:  # noqa: BLE001 — best-effort cleanup
        log(f"Unable to close browser session for {test_id}: {exc}")


def visit_and_acknowledge(driver, client: httpx.Client, test_id: str, method: str, url: str) -> None:
    drive_url(driver, method, url)
    response = client.post(
        f"{CONFORMANCE_SERVER}/api/runner/browser/{test_id}/visit",
        params={"url": url},
    )
    response.raise_for_status()


def main() -> None:
    drivers: dict = {}
    processed: set = set()
    active_test_id = None

    with httpx.Client(verify=False, timeout=15.0) as client:
        while True:
            try:
                running = client.get(f"{CONFORMANCE_SERVER}/api/runner/running").json()
            except Exception as exc:  # noqa: BLE001 — poll loop must keep going
                log(f"Unable to read running tests: {exc}")
                time.sleep(POLL_SECONDS)
                continue

            for test_id in running:
                try:
                    status = client.get(f"{CONFORMANCE_SERVER}/api/runner/{test_id}").json()
                except Exception as exc:  # noqa: BLE001
                    log(f"Unable to read status for {test_id}: {exc}")
                    continue

                for item in get_browser_items(status):
                    url = item["url"]
                    method = item["method"].upper()
                    key = (test_id, method, url)

                    if key in processed:
                        continue

                    if active_test_id is not None and active_test_id != test_id:
                        for old_test_id in list(drivers):
                            if old_test_id != test_id:
                                close_driver(drivers, old_test_id)

                    active_test_id = test_id
                    driver = get_driver(drivers, test_id)

                    try:
                        visit_and_acknowledge(driver, client, test_id, method, url)
                        processed.add(key)
                    except Exception as exc:  # noqa: BLE001
                        log(f"Browser visit failed: {type(exc).__name__}: {exc}")
                        close_driver(drivers, test_id)

            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
