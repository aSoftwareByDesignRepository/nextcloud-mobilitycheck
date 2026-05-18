# Release folder (MobilityCheck)

Documentation for **shipping** MobilityCheck to the Nextcloud App Store and for **GitHub Releases** on **[`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck)** — the public repo that contains **only** this app.

| File | Purpose |
|------|---------|
| [APPSTORE-RELEASE.md](./APPSTORE-RELEASE.md) | Nextcloud App Store: build tarball, checksums, OpenSSL signature, GitHub Release |
| [STANDALONE_REPO.md](./STANDALONE_REPO.md) | **Optional:** sync `apps/mobilitycheck` from a **private monorepo** into `nextcloud-mobilitycheck` (`git subtree`) |

**Canonical public repo:** `https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck`

Update **`appinfo/info.xml`** `<repository>` / `<bugs>` if your fork uses different URLs.

**Generated** (gitignored — see app `.gitignore`):

- `mobilitycheck-*.tar.gz`, signatures, local `SIGNATURE-*.txt`

## Quick tarball

From a clone of **`nextcloud-mobilitycheck`** (app at repo root):

```bash
./release/build-appstore-archive.sh X.Y.Z
```

From a **monorepo** (app at `apps/mobilitycheck`):

```bash
./apps/mobilitycheck/release/build-appstore-archive.sh X.Y.Z
```

Manual `tar` examples: **APPSTORE-RELEASE.md**.

Details: **APPSTORE-RELEASE.md**.
