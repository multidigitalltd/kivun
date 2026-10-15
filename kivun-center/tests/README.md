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

All of them run in CI — but only on a push to `main` or `develop`, or a pull
request aimed at one of those. A push to a working branch with no pull request
open starts nothing, so run `composer check` before pushing rather than waiting
for a run that will not happen.

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
| `FormSubmissionDetailsTest.php` | Extra answers through generic capture, Kivun leads, and free/paid course registration, including multiple choices and mapped hidden fields. | Form actions saved only their mapped fields, discarding other answers. |
| `SubmissionDetailsTest.php` | Complete, escaped, read-only visitor details, including existing records and multiline answers. | The lead lists showed staff notes while hiding saved visitor answers. |
| `MultiFilterTest.php` | Reading a filter that may hold several choices: ids and names, one or many, and what survives to reach the query. | Everything downstream builds an IN clause from whatever comes back. A zero kept by accident matches nothing and reads on screen as "there are no leads"; a blank name quietly pulls in every lead that never gave one. |
| `LeadStatusTest.php` | Which statuses count as somebody's decision, what the control shows for one that is nobody's, what clearing a status puts back, and that the filter's "לא טופלו" expands into the words the rows are actually stored as — a waiting-list sign-up among them, which is shown and still waiting. | Every lead is stored with a status the moment it arrives, so the label under the dropdown read "ליד חדש" on the lead nobody had opened and on the one worked through yesterday — the column answered the one question it was added to answer with the same word every time. Hiding those statuses is cheap; what is not is the rest of the system reading them, so the vocabulary, the dashboard count and the filters still have to find the rows. |
| `GenderFilterTest.php` | That the leads list's gender filter and the rota's split agree about every value a form might send, and that the SQL is built from one list of words. | "male" is inside "female", "man" inside "woman", "נער" inside "נערה". A men's filter that matched on substrings alone would list women, and it would look right until somebody read the names. |
| `LeadHandlerTest.php` | That the coordinator the rota picked is remembered for the rest of the request, shown by name, and written to a column that lines up with its placeholder. | The split was invisible after the fact — the only trace was one option the next submission overwrote. Reading the choice back by asking the rota again would answer with the *next* person and skew the very split it reported on. |
| `ShortLinkTest.php` | What a short code may contain, that a generated one has no characters that get read wrong, and that editing a link keeps the code it already has. | Hebrew in the code would percent-encode into the same noise the short link exists to replace. And a code regenerated on every save breaks every link already sent out — silently, because the screen shows the new one and looks right. |
| `MercazJobFieldsTest.php` | What a job must carry before it is broadcast, and reading the warnings their API returns. | Their API takes a job with no settlement, type or number, answers 201 and files it where nobody can find it. 23 jobs went up that way and neither end said a word. |
| `JobVocabularyTest.php` | That every job type and feature we offer exists in their vocabulary under that exact name, and that the posting form collects what the broadcast needs. | Terms are matched across the two sites by name, so one wrong letter drops the field silently — `אדרכילות/בנייה` sat in the seed list with its letters transposed. |
| `AiImageSizeTest.php` | The shape a generated cover is saved at: the configured size, the crop that produces it, and the ratio written into the prompt. | A content page renders its hero at the image's own ratio, so a 16:9 cover filled most of the screen before the first line of text. The crop that fixes it fails quietly — a box a few pixels out still produces a picture, just a squashed one. |
| `FilledJobTest.php` | That a filled job still shows its form and says why, and that the candidate's letter and the coordinator's notification both know the role was taken. | A filled job closed its form, turning away the people worth calling about the next one like it. Three files have to agree, and a disagreement only shows as a letter promising to be in touch about a job that is gone. |

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
