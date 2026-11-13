# Using the fork in an app (empire-v2)

The app imports Livewire's built JS from the Composer package (`vendor/livewire/livewire/dist/livewire.esm`, see
`resources/js/boot.js`), and this repo never commits `dist/`. So the app needs a **tagged release that includes `dist/`**.

## 1. Cut a release from the performance branch
```bash
git switch perf/core-performance          # clean working tree
perf/release.sh 4.4.8-p1                  # builds dist/, commits it on release/v4.4.8-p1, tags v4.4.8-p1 (local only)
git push origin release/v4.4.8-p1 v4.4.8-p1
```
`4.4.8-p1` is a Composer *patch level* of 4.4.8 (stable, higher than 4.4.8), so the app's `^4.3.3` constraint accepts it.
Don't use suffixes such as `-perf.1`; Composer doesn't treat them as a version. The upstream "Draft Release" workflow
checks out `4.x` only, so it can't be used for this branch without editing it.

## 2. Point the app at the fork (app repo, its own branch/PR)
In the app's `composer.json`:
```json
"repositories": [
  { "type": "vcs", "url": "git@github.com:empire-medical/livewire.git" }
],
"require": { "livewire/livewire": "^4.3.3" }
```
then `composer update livewire/livewire`. A VCS repository listed first is canonical, so Composer takes `livewire/livewire`
only from the fork. Check that `composer show livewire/livewire` reports `4.4.8-p1` and that
`vendor/livewire/livewire/dist/livewire.esm.js` exists. CI/deploy needs read access to the fork repository
(deploy key or token), and the Vite bundle must be rebuilt (`vite build`) because it embeds `livewire.esm`.

## 3. Prerequisites in the app for the client gains (the two app edits)
The unchanged-element skip only fires when per-render markup is deterministic and rows are keyed:
- no `uniqid()`/random ids inside keyed rows (they make every render differ);
- table root `wire:key` must not contain the row ids (otherwise any row-set change replaces the whole table).
Without them the fork behaves like upstream. See `codemap/app-side-ab.md`.

## 4. Switches and behaviour changes
- `config('livewire.morph_skip_unchanged')` (default `true` in the fork). `false` restores upstream morphing.
- With the skip on, edits made to an unchanged keyed subtree by anything other than Alpine/Livewire are not reset on the
  next render (upstream resets them).
- Staying on the fork does not require merging upstream releases; to take an upstream fix, merge or cherry-pick it into
  the branch and cut a new `-pN`.

## 5. Rolling back
Set the constraint back to a tagged upstream version (remove the VCS repository), `composer update livewire/livewire`,
rebuild the Vite bundle.
