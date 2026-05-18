# Optional: push from a private monorepo into `nextcloud-mobilitycheck`

**[`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck)** on GitHub is the **canonical public repository** for MobilityCheck: it contains **only** this app (source, issues, releases). Most contributors and users never need this document.

Use the workflow below **only if** you develop MobilityCheck inside a **larger private repository** (e.g. a Nextcloud “all apps” monorepo) and want to **publish** the same tree to **`nextcloud-mobilitycheck`** without maintaining two codebases by hand.

Develop in the private repo (canonical GitHub name **`nextcloud-development`**; local folder may differ).

**`nextcloud-development`** tracks this app as a **git submodule** at `apps/mobilitycheck` (branch **`main`**). Workflow:

1. Commit and push changes **here** (`nextcloud-mobilitycheck`).
2. In the monorepo: `cd apps/mobilitycheck && git pull origin main`, then from monorepo root commit the updated submodule pointer and push.

Older setups that **vendor** `apps/mobilitycheck/` without a submodule can still publish with **`git subtree`** (see below) or the convenience script:

`scripts/push-public-app-subtree.sh mobilitycheck aSoftwareByDesignRepository/nextcloud-mobilitycheck`

That runs `git subtree split` on `apps/mobilitycheck` and pushes to `main` on the standalone repo.

| | |
|--|--|
| **Public app repo** | `aSoftwareByDesignRepository/nextcloud-mobilitycheck` |
| **Clone URL** | `https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck.git` |

**`appinfo/info.xml`** should list **`nextcloud-mobilitycheck`** for **`<repository>`** and **`<bugs>`** (already the case in this tree).

---

## One-time setup (monorepo)

From the **monorepo root** (parent of `apps/`):

```bash
cd /path/to/nextcloud-development

git remote add mobilitycheck-public git@github.com:aSoftwareByDesignRepository/nextcloud-mobilitycheck.git
# git remote add mobilitycheck-public https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck.git
```

---

## Push app sources (subtree / vendored tree): `git subtree push`

Use this only when `apps/mobilitycheck` is **not** a submodule.

```bash
cd /path/to/nextcloud-development
git subtree push --prefix=apps/mobilitycheck mobilitycheck-public main
```

### Variant: split to a branch, then push

```bash
cd /path/to/nextcloud-development
git subtree split --prefix=apps/mobilitycheck -b split-mobilitycheck
git push mobilitycheck-public split-mobilitycheck:main --force
git branch -D split-mobilitycheck
```

Use `--force` only when you intend to replace the remote history.

---

## After changes (monorepo uses submodule)

When **`nextcloud-development`** uses the submodule: publish **here first**, then bump the pointer in the monorepo (`git add apps/mobilitycheck` after pulling inside the submodule).

## Releases (tarball + GitHub Release)

Build the `.tar.gz` as in [APPSTORE-RELEASE.md](./APPSTORE-RELEASE.md), then attach it to a **tag on `nextcloud-mobilitycheck`**, not on the private monorepo.

```bash
export GH_REPO=aSoftwareByDesignRepository/nextcloud-mobilitycheck
gh release create "v${VERSION}" --title "v${VERSION}" "mobilitycheck-${VERSION}.tar.gz"
```

---

## `info.xml` URLs

- `https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck`
- `https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck/issues`
