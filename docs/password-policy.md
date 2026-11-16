# Password policy

Instance admins configure password rules under **Settings → Authentication & security**. The same rule applies to registration, password reset, password change, and admin-created users (via Laravel `Password::defaults()` and `PasswordValidationRules`).

## Settings

| Setting                   | Default (non-production) | Default (production) | Notes                |
| ------------------------- | ------------------------ | -------------------- | -------------------- |
| Minimum length            | 8                        | 12                   | Floor 6, ceiling 128 |
| Require mixed case        | off                      | on                   | Upper and lower case |
| Require numbers           | off                      | on                   |                      |
| Require symbols           | off                      | on                   |                      |
| Reject breached passwords | **off**                  | **off**              | Opt-in only          |

## Reject breached passwords (Have I Been Pwned)

This check is **off by default**. Signaculum has no telemetry: the instance does not contact external services unless an admin turns this on.

When enabled, Laravel's `Password::uncompromised()` sends only the first five characters of the SHA-1 hash of the candidate password to the [Have I Been Pwned Pwned Passwords range API](https://haveibeenpwned.com/API/v3#PwnedPasswords) (k-anonymity). The full password and the full hash never leave the server. HIBP returns a list of matching suffixes; Signaculum compares locally.

If the instance cannot reach that API, validation fails closed for that check (Laravel's default). Turn the setting off to keep password validation fully local.
