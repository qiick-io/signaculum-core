#!/usr/bin/env python3
"""
Drive OpenID Foundation front-channel URLs against Signaculum with Selenium.

The suite exposes browser URLs through its runner API; HtmlUnit is not enough for
the Inertia login and consent pages, so this worker visits them in Chromium.
"""

from __future__ import annotations

import base64
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

    try:
        first_present(
            driver,
            ((By.CSS_SELECTOR, '[data-test="approve-authorization"]'),),
            timeout=3,
        )

        return True
    except NoSuchElementException:
        return False


def is_logout_confirm_page(driver) -> bool:
    parsed = urllib.parse.urlsplit(driver.current_url)

    if parsed.hostname != OP_HOST:
        return False

    path = parsed.path.rstrip("/")

    return "end-session" in path or path.endswith("/logout") or bool(
        driver.find_elements(By.CSS_SELECTOR, 'button[type="submit"]')
    ) and "oauth" in path


def login(driver) -> bool:
    """Sign in via HTTP using the browser's session cookies.

    Headless Chromium does not reliably fire the Inertia Fortify submit
    handler. POSTing /login with the existing session keeps `url.intended`
    (the authorize URL) and then we follow the redirect in Selenium.
    """
    if not is_login_page(driver):
        return False

    log(f"Logging in at {describe_url(driver.current_url)}")
    base_url = os.environ.get("SIGNACULUM_BASE_URL", "https://signaculum-proxy:8443").rstrip("/")
    browser_cookies = {cookie["name"]: cookie["value"] for cookie in driver.get_cookies()}
    xsrf_token = urllib.parse.unquote(browser_cookies.get("XSRF-TOKEN", ""))

    if not xsrf_token:
        raise RuntimeError("Login page has no XSRF-TOKEN cookie")

    with httpx.Client(verify=False, timeout=30.0, follow_redirects=False, cookies=browser_cookies) as client:
        response = None

        for attempt in range(1, 6):
            response = client.post(
                f"{base_url}/login",
                data={
                    "email": OIDC_TEST_USER,
                    "password": OIDC_TEST_PASSWORD,
                },
                headers={
                    "Content-Type": "application/x-www-form-urlencoded",
                    "X-XSRF-TOKEN": xsrf_token,
                    "Origin": base_url,
                    "Referer": f"{base_url}/login",
                    "Accept": "text/html, application/xhtml+xml",
                },
            )

            if response.status_code != 429:
                break

            sleep_seconds = min(30, 5 * attempt)
            log(f"Login rate-limited (429); sleeping {sleep_seconds}s (attempt {attempt})")
            time.sleep(sleep_seconds)
            # Refresh CSRF after waiting.
            driver.get(base_url + "/login")
            browser_cookies = {cookie["name"]: cookie["value"] for cookie in driver.get_cookies()}
            xsrf_token = urllib.parse.unquote(browser_cookies.get("XSRF-TOKEN", ""))
            client.cookies.clear()

            for cookie_name, cookie_value in browser_cookies.items():
                client.cookies.set(cookie_name, cookie_value)

        if response is None or response.status_code not in {200, 302, 303}:
            status = response.status_code if response is not None else "none"
            raise RuntimeError(f"HTTP login failed with status {status}")

        # httpx may keep multiple Set-Cookie values for the session name; take
        # the jar order (last write wins for Selenium).
        merged_cookies = {cookie.name: cookie.value for cookie in client.cookies.jar}

        for cookie_name, cookie_value in merged_cookies.items():
            try:
                driver.delete_cookie(cookie_name)
            except Exception:
                pass

            driver.add_cookie(
                {
                    "name": cookie_name,
                    "value": cookie_value,
                    "path": "/",
                }
            )

        redirect_url = response.headers.get("Location") or base_url + "/dashboard"

    if redirect_url.startswith("/"):
        redirect_url = base_url + redirect_url

    # Laravel may have stored url.intended as http:// behind the TLS proxy.
    if redirect_url.startswith("http://"):
        redirect_url = "https://" + redirect_url.removeprefix("http://")

    log(f"HTTP login redirected to {describe_url(redirect_url)}")
    driver.get(redirect_url)

    if is_login_page(driver):
        raise RuntimeError("HTTP login completed but Selenium is still on the login page")

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
        approve_button = first_present(
            driver,
            ((By.CSS_SELECTOR, '[data-test="approve-authorization"]'),),
            timeout=10,
        )
        approve_form = approve_button.find_element(By.XPATH, "ancestor::form")
    except NoSuchElementException:
        return False

    log("Granting consent")
    base_url = os.environ.get("SIGNACULUM_BASE_URL", "https://signaculum-proxy:8443").rstrip("/")
    browser_cookies = {cookie["name"]: cookie["value"] for cookie in driver.get_cookies()}
    csrf_inputs = {
        element.get_attribute("name"): element.get_attribute("value")
        for element in approve_form.find_elements(By.CSS_SELECTOR, 'input[type="hidden"]')
    }
    auth_token = csrf_inputs.get("auth_token")
    csrf_token = csrf_inputs.get("_token") or urllib.parse.unquote(
        browser_cookies.get("XSRF-TOKEN", "")
    )

    if not auth_token or not csrf_token:
        raise RuntimeError("Consent form is missing auth_token or CSRF token")

    with httpx.Client(verify=False, timeout=30.0, follow_redirects=False, cookies=browser_cookies) as client:
        response = client.post(
            f"{base_url}/oauth/authorize",
            data={
                "_token": csrf_token,
                "auth_token": auth_token,
            },
            headers={
                "Content-Type": "application/x-www-form-urlencoded",
                "X-XSRF-TOKEN": urllib.parse.unquote(browser_cookies.get("XSRF-TOKEN", csrf_token)),
                "Origin": base_url,
                "Referer": driver.current_url,
                "Accept": "text/html, application/xhtml+xml",
            },
        )

        if response.status_code not in {200, 302, 303}:
            raise RuntimeError(f"Consent POST failed with status {response.status_code}")

        merged_cookies = {cookie.name: cookie.value for cookie in client.cookies.jar}

        for cookie_name, cookie_value in merged_cookies.items():
            try:
                driver.delete_cookie(cookie_name)
            except Exception:
                pass

            driver.add_cookie({"name": cookie_name, "value": cookie_value, "path": "/"})

        redirect_url = response.headers.get("Location")

    if not redirect_url:
        raise RuntimeError("Consent POST did not return a redirect Location")

    if redirect_url.startswith("/"):
        redirect_url = base_url + redirect_url

    if redirect_url.startswith("http://"):
        redirect_url = "https://" + redirect_url.removeprefix("http://")

    log(f"Consent redirected to {describe_url(redirect_url)}")
    driver.get(redirect_url)

    return True


def read_inertia_page(driver) -> dict | None:
    """Read the Inertia page JSON before React has painted the form."""
    try:
        page = driver.execute_script(
            """
            const script = document.querySelector('script[data-page][type="application/json"]');
            if (!script || !script.textContent) {
                return null;
            }
            try {
                return JSON.parse(script.textContent);
            } catch (error) {
                return null;
            }
            """
        )
    except Exception:
        return None

    return page if isinstance(page, dict) else None


def confirm_logout_if_present(driver) -> bool:
    parsed = urllib.parse.urlsplit(driver.current_url)

    if parsed.hostname != OP_HOST:
        return False

    if "end-session" not in parsed.path and "/oauth/" not in parsed.path:
        return False

    page = read_inertia_page(driver)
    props: dict = {}

    if page and page.get("component") == "oauth/logout":
        props = page.get("props") or {}
    else:
        try:
            confirm = first_present(
                driver,
                ((By.CSS_SELECTOR, 'button[type="submit"]'),),
                timeout=2,
            )
            logout_form = confirm.find_element(By.XPATH, "ancestor::form")
            props = {
                "clientId": None,
                "postLogoutRedirectUri": None,
                "state": None,
                "idTokenHint": None,
            }
            for element in logout_form.find_elements(By.CSS_SELECTOR, "input"):
                name = element.get_attribute("name")
                value = element.get_attribute("value")

                if name == "client_id":
                    props["clientId"] = value
                elif name == "post_logout_redirect_uri":
                    props["postLogoutRedirectUri"] = value
                elif name == "state":
                    props["state"] = value
                elif name == "id_token_hint":
                    props["idTokenHint"] = value
        except NoSuchElementException:
            return False

    log("Confirming RP-initiated logout")
    base_url = os.environ.get("SIGNACULUM_BASE_URL", "https://signaculum-proxy:8443").rstrip("/")
    # Post from inside Chromium so the session + XSRF cookies stay same-origin
    # (httpx from the runner container hits Laravel CSRF 419).
    result = driver.execute_async_script(
        """
        const done = arguments[arguments.length - 1];
        const props = arguments[0];
        const params = new URLSearchParams();
        if (props.clientId) { params.set('client_id', props.clientId); }
        if (props.postLogoutRedirectUri) {
          params.set('post_logout_redirect_uri', props.postLogoutRedirectUri);
        }
        if (props.state) { params.set('state', props.state); }
        if (props.idTokenHint) { params.set('id_token_hint', props.idTokenHint); }
        const match = document.cookie.match(/(?:^|; )XSRF-TOKEN=([^;]+)/);
        const csrf = match ? decodeURIComponent(match[1]) : '';
        fetch('/oauth/end-session/confirm', {
          method: 'POST',
          credentials: 'same-origin',
          redirect: 'manual',
          headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            'X-XSRF-TOKEN': csrf,
            'Accept': 'text/html, application/xhtml+xml',
          },
          body: params.toString(),
        }).then(async (response) => {
          done({
            status: response.status,
            location: response.headers.get('Location'),
            type: response.type,
          });
        }).catch((error) => {
          done({ status: 0, error: String(error) });
        });
        """,
        {
            "clientId": props.get("clientId"),
            "postLogoutRedirectUri": props.get("postLogoutRedirectUri"),
            "state": props.get("state"),
            "idTokenHint": props.get("idTokenHint"),
        },
    )

    if not isinstance(result, dict) or result.get("status") not in {0, 200, 302, 303}:
        raise RuntimeError(f"Logout confirm POST failed: {result}")

    # Opaque redirect responses often surface as status 0 with type 'opaqueredirect'.
    redirect_url = result.get("location") if isinstance(result, dict) else None

    if not redirect_url:
        # Follow by reloading: Laravel session is already cleared in-browser.
        driver.get(base_url + "/login")

        return True

    if redirect_url.startswith("/"):
        redirect_url = base_url + redirect_url

    if redirect_url.startswith("http://"):
        redirect_url = "https://" + redirect_url.removeprefix("http://")

    log(f"Logout confirm redirected to {describe_url(redirect_url)}")
    driver.get(redirect_url)

    return True


def is_conformance_callback(url: str) -> bool:
    parsed = urllib.parse.urlsplit(url)

    return parsed.netloc in {"nginx:8443", "localhost:8443"} and (
        parsed.path.startswith("/test/a/") or parsed.path.startswith("/test-mtls/a/")
    )


def drive_url(driver, method: str, url: str) -> str:
    log(f"Visiting {method} {describe_url(url)}")
    is_end_session_visit = "end-session" in urllib.parse.urlsplit(url).path
    end_session_without_form_since: float | None = None

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
            end_session_without_form_since = None

        if is_conformance_callback(current_url):
            log(f"Reached conformance callback {describe_url(current_url)}")

            return current_url

        # Auth-code / logout redirects land on the suite host (log-detail, /test/a, …).
        if SUITE_HOST and urllib.parse.urlsplit(current_url).hostname == SUITE_HOST:
            log(f"Reached suite host {describe_url(current_url)}")

            return current_url

        if is_login_page(driver):
            # After RP-initiated logout without post_logout_redirect_uri the OP
            # sends the browser to /login. Do not sign the user back in.
            if is_end_session_visit:
                log("End-session finished on login; treating visit as complete")

                return current_url

            login(driver)

            if SUITE_HOST and urllib.parse.urlsplit(driver.current_url).hostname == SUITE_HOST:
                log(f"Reached suite host after login {describe_url(driver.current_url)}")

                return driver.current_url

            continue

        if grant_consent_if_present(driver):
            if SUITE_HOST and urllib.parse.urlsplit(driver.current_url).hostname == SUITE_HOST:
                log(f"Reached suite host after consent {describe_url(driver.current_url)}")

                return driver.current_url

            continue

        if confirm_logout_if_present(driver):
            if SUITE_HOST and urllib.parse.urlsplit(driver.current_url).hostname == SUITE_HOST:
                log(f"Reached suite host after logout {describe_url(driver.current_url)}")

                return driver.current_url

            # Confirmation without RP redirect ends on OP login/dashboard.
            log(f"Logout confirmed; finishing visit at {describe_url(driver.current_url)}")

            return driver.current_url

        # Bad post_logout_redirect_uri (and similar) abort with 400 and no logout page.
        if is_end_session_visit and "end-session" in urllib.parse.urlsplit(current_url).path:
            inertia_page = read_inertia_page(driver)
            is_logout_component = bool(inertia_page and inertia_page.get("component") == "oauth/logout")
            has_confirm = is_logout_component or bool(
                driver.find_elements(By.CSS_SELECTOR, 'button[type="submit"]')
            )

            if has_confirm:
                end_session_without_form_since = None
            else:
                if end_session_without_form_since is None:
                    end_session_without_form_since = time.monotonic()
                elif time.monotonic() - end_session_without_form_since >= 5:
                    log("End-session page has no confirm form; treating as terminal response")

                    return current_url

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


def upload_pending_screenshots(driver, client: httpx.Client, test_id: str) -> None:
    """Fill REVIEW image placeholders after end-session visits.

    Several RP-initiated logout modules stay WAITING until a PNG/JPEG of the
    OP error or logged-out page is uploaded to `/api/log/{id}/images/{upload}`.
    """
    try:
        placeholders = client.get(f"{CONFORMANCE_SERVER}/api/log/{test_id}/images").json()
    except Exception as exc:  # noqa: BLE001
        log(f"Unable to list image placeholders for {test_id}: {exc}")

        return

    pending = [
        entry
        for entry in placeholders
        if isinstance(entry, dict) and entry.get("upload") and not entry.get("img")
    ]

    if not pending:
        return

    try:
        png_bytes = driver.get_screenshot_as_png()
    except Exception as exc:  # noqa: BLE001
        log(f"Unable to capture screenshot for {test_id}: {exc}")

        return

    encoded = "data:image/png;base64," + base64.b64encode(png_bytes).decode("ascii")

    for entry in pending:
        placeholder = entry["upload"]
        log(f"Uploading screenshot for placeholder {placeholder} ({test_id})")
        response = client.post(
            f"{CONFORMANCE_SERVER}/api/log/{test_id}/images/{placeholder}",
            content=encoded,
            headers={"Content-Type": "text/plain"},
        )

        if response.status_code >= 400:
            log(f"Screenshot upload failed ({response.status_code}): {response.text[:200]}")
        else:
            log(f"Screenshot uploaded for {test_id}")


def visit_and_acknowledge(driver, client: httpx.Client, test_id: str, method: str, url: str) -> None:
    drive_url(driver, method, url)

    if "end-session" in urllib.parse.urlsplit(url).path:
        upload_pending_screenshots(driver, client, test_id)

    response = client.post(
        f"{CONFORMANCE_SERVER}/api/runner/browser/{test_id}/visit",
        params={"url": url},
    )
    response.raise_for_status()

    if "end-session" in urllib.parse.urlsplit(url).path:
        upload_pending_screenshots(driver, client, test_id)


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

                # Visits already acknowledged but still WAITING on a screenshot.
                if test_id in drivers and not get_browser_items(status):
                    try:
                        upload_pending_screenshots(drivers[test_id], client, test_id)
                    except Exception as exc:  # noqa: BLE001
                        log(f"Deferred screenshot upload failed for {test_id}: {exc}")

            time.sleep(POLL_SECONDS)


if __name__ == "__main__":
    try:
        main()
    except KeyboardInterrupt:
        sys.exit(0)
