# Tests

## Running them

```bash
cd kivun-center
composer install          # once
npm ci                    # once — the asset check needs the build tools
composer check            # lint + unit tests + asset check
```

Individually:

| Command | What it checks |
| --- | --- |
| `composer lint` | PHPCS, WordPress standard. `tests/` is excluded — the bootstrap reimplements WordPress functions, so rules like "use `wp_strip_all_tags`" are meaningless there. |
| `composer test` | The unit tests below. |
| `./bin/check-assets.sh` | That `*.min.css` / `*.min.js` are the ones built from the sources in this commit. |

All four run in CI on every push and pull request.

## Why these tests and not others

There is no WordPress here — no database, no install. The units covered are the
ones that have actually gone wrong on this site, and every one of them failed
*silently*: nothing on screen looked broken, and the cost only showed up later
as a lead on the wrong desk or a campaign that looked quiet.

| File | What it pins down | What went wrong before |
| --- | --- | --- |
| `CoordinatorsTest.php` | The rota that shares candidates between coordinators. | A share is invisible until somebody counts a month later, so the split is checked over a hundred submissions rather than by eye. |
| `PhonesTest.php` | Matching a dialled number; reading the switchboard's dates; the call filters. | A landline that did not match filed calls against no number at all. `12/09/2026` read the American way put a call in December. |
| `FormsRouterTest.php` | Which submissions belong to the jobs coordinators. | The rota was consulted for every form carrying a gender field, which swept in the landing pages and skewed the split for the candidates it was built for. |
| `MailerTest.php` | That the letters are right-to-left documents, and that the sign-off names the coordinator. | A letter only looks wrong in somebody else's inbox. |

## `bin/check-assets.sh`

The site loads `frontend.min.css`, not `frontend.css`.

A release once shipped a stylesheet that did not contain the fix it was
released for: `node_modules` was missing, `cleancss` was not found, and the
failure was swallowed by a pipe so the build reported success. The fix was in
the source, committed, reviewed and released — and the site could not see it.
It was reported again as unfixed.

The check rebuilds into a scratch directory and compares. It touches nothing.

## Adding a test

Stubs live in `tests/bootstrap.php`. Add what the unit under test actually
calls, and keep the stub faithful to WordPress — `sanitize_textarea_field()`
there really does destroy percent-encoded sequences, because core does, and a
bug on this site depended on it. A stub that is kinder than WordPress turns a
passing test into a lie.

`Kivun_Test_State::reset()` in `setUp()` keeps one test's options out of the
next.
