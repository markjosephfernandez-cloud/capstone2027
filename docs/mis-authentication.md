# Foundation University MIS authentication

The backend token client is available as `service('misTokens')`. The API key is configured only in the ignored local `.env` as `mis.apiKey`; `env.example` contains an empty placeholder. The default endpoint is `https://mis.foundationu.com/sandbox/api`.

The backend uses the website/IP restriction headers. MIS must allow the backend website or outgoing IP. Android/iOS restriction headers are not used because requests originate from PHP, not the mobile app.

```php
// Obtain an initial application token pair.
$tokens = service('misTokens')->issue();

// Later, exchange the latest refresh token for a new pair.
$tokens = service('misTokens')->refresh($tokens['refresh_token']);
```

`generate()` returns the initial refresh token. `refresh()` and `issue()` return `access_token`, `refresh_token`, and `expires_at`. Both the documented flat response and the sandbox's observed `data` envelope are accepted. HTTPS verification is enabled; redirects are disabled so credentials are not forwarded to another destination. Errors omit response bodies and secrets.

The client does not persist tokens or automatically refresh them. The eventual consuming service must store the latest pair server-side, serialize refresh operations, and replace the previous refresh token after every successful exchange. Do not send these application tokens to browsers or the Expo client.

## Verification

A live sandbox generate → refresh exchange succeeded with the locally configured API key. Token values were not printed or committed. Run the mocked client tests with:

```sh
./vendor/bin/phpunit --no-coverage tests/MisTokenServiceTest.php
```

## Employee login integration

The MIS request is now implemented in `MisLoginService`: POST `/v1/employees/login` with the server-side `API-Key` header and JSON string fields `employee_id` and `password`. It does not need to obtain application tokens first. HTTPS verification remains enabled and redirects are disabled.

Both web and mobile API login use the shared `CredentialsService`. With `mis.loginEnabled = true`, the service checks the existing local account is active, then verifies its password through MIS. MIS rejection never falls back to local passwords. Local roles, department access, and account provisioning remain controlled by Greyhound. MIS passwords are neither saved to the database nor copied into sessions. Provider exceptions are sanitized; temporary authentication failures return a friendly web message or API HTTP 503.

**Activation is pending the successful-login response sample.** `mis.loginEnabled` defaults to false, so current local login remains available. The provisional parser requires HTTP 200, `status: "success"`, and a matching `data.employee_id`. This is a conservative unverified contract, not an observed successful response; it must be checked against the provider's sample before enabling MIS login in `.env`. An unknown response fails closed. A real employee login has not yet been tested.

Tests cover the documented request, credential rejection, malformed responses, identity mismatch, network errors, inactive/missing accounts, permission preservation, no local-password fallback in MIS mode, and existing local mode.

## Employee lookup

`service('misEmployees')->find(210428)` retrieves a single employee using a server-side access token. It returns only employee ID, name fields, position, and department fields. An empty result or HTTP 404 returns `null`; provider errors, malformed responses, or an unexpected employee ID raise a sanitized exception. The client does not create users, set passwords, or assign roles.

A live lookup through this service succeeded for employee 210428. Employee lookup tests are in `tests/MisEmployeeServiceTest.php`.

## Login endpoint check

An empty POST to `/v1/employees/login` with only an access-token bearer header returned HTTP 401 (`Unauthorized access. API key is invalid.`). With both `API-Key` and the bearer header, an empty POST returned HTTP 403 (`Fields must not be empty.`). This indicates the API key header is required for the tested request. It does not establish whether the bearer header is also required, which credential field names/encoding are accepted, or what response proves successful authentication. No employee password was submitted.
