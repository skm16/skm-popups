# WordPress.org repository assets

These files are **not** part of the plugin. WordPress.org serves them from the
`assets/` directory of the plugin's SVN repository, not from the plugin itself,
and `tools/build-zip.mjs` excludes this directory from the distributable archive
along with every other dotfile.

## What is here

| File | Shows |
|---|---|
| `screenshot-1.png` | The popup editor sidebar, all five panels expanded |
| `screenshot-2.png` | The editor warnings, including an unavailable condition preserved read-only |
| `screenshot-3.png` | The light theme, modal layout |
| `screenshot-4.png` | The dark theme, modal layout |

Every one is a real capture from the end-to-end WordPress instance rather than a
mockup. Screenshots 3 and 4 are the visual-regression baselines
`tests/e2e/themes.spec.js` asserts against, which means the marketing images and
the tested rendering cannot drift apart — if the theme changes, the baseline
fails and both get updated together.

The numbering matches the `== Screenshots ==` list in `readme.txt`. WordPress.org
pairs them by index, so inserting one means renumbering the rest *and* the readme.

## What is missing

- `banner-1544x500.png` and `banner-772x250.png`
- `icon-256x256.png` and `icon-128x128.png`

These are brand design work and have not been produced. A submission is accepted
without them — the directory listing simply falls back to a generated placeholder
— so this is a presentation gap rather than a blocker, and it is recorded here
rather than left to be discovered at upload time.
