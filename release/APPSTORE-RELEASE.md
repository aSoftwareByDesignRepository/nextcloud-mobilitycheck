# Nextcloud App Store — release workflow (MobilityCheck)

This file is the **MobilityCheck-specific** checklist. The **canonical** procedure is the upstream **App Developer Guide**:

**[App Developer Guide — Nextcloud App Store](https://nextcloudappstore.readthedocs.io/en/latest/developer.html)**

Replace `X.Y.Z` with the real version (e.g. `0.4.3`). App id is **`mobilitycheck`** (lowercase, matches the top-level folder inside the `.tar.gz`).

**Repository:** build and release from **[`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck)** — that GitHub repo contains **only** MobilityCheck.

---

## 1. Obtaining a certificate (upstream steps)

From the guide: store keys under `~/.nextcloud/certificates/`, then generate key + CSR (`CN` must equal the app id):

```bash
mkdir -p ~/.nextcloud/certificates/
cd ~/.nextcloud/certificates/
openssl req -nodes -newkey rsa:4096 -keyout mobilitycheck.key -out mobilitycheck.csr -subj "/CN=mobilitycheck"
```

Open a **pull request** on [nextcloud/app-certificate-requests](https://github.com/nextcloud/app-certificate-requests) with the contents of **`mobilitycheck.csr`**. After approval, save the signed public cert as **`mobilitycheck.crt`** next to **`mobilitycheck.key`**. Never commit the `.key` file.

---

## 2. Registering the app id (one-time)

After you have **`mobilitycheck.crt`**, use the [register app](https://apps.nextcloud.com/developer/apps/new) UI. The guide asks for:

- **Certificate:** paste **`mobilitycheck.crt`**
- **Signature** over the app id:

```bash
echo -n "mobilitycheck" | openssl dgst -sha512 -sign ~/.nextcloud/certificates/mobilitycheck.key | openssl base64
```

---

## 3. Version, `info.xml`, and `CHANGELOG.md`

1. Bump **`appinfo/info.xml`**: `<version>X.Y.Z</version>` and adjust **`<dependencies><nextcloud …/></dependencies>`** if needed.
2. Update **`CHANGELOG.md`** at the app root. The store imports changelog from **`CHANGELOG.md`**; the release heading must match the semantic version in `info.xml (`## X.Y.Z`).
3. Optional: **`release/GITHUB_RELEASE_NOTES_X.Y.Z.md`** for GitHub Releases.

> Each published update must use a **new** version number.

---

## 4. Build the installable `.tar.gz`

The uploaded archive must:

- Contain **exactly one** top-level folder named **`mobilitycheck`**.
- Contain **`mobilitycheck/appinfo/info.xml`**.
- **Not** contain **`.git`** ([blacklisted](https://nextcloudappstore.readthedocs.io/en/latest/developer.html#blacklisted-files)).

**Recommended — clone of [`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck)**:

```bash
cd /path/to/nextcloud-mobilitycheck
./release/build-appstore-archive.sh X.Y.Z
```

**Same script from a private monorepo** (app under `apps/mobilitycheck/`):

```bash
./apps/mobilitycheck/release/build-appstore-archive.sh X.Y.Z
```

This runs `composer install --no-dev`, then packs with excludes for `tests`, prior release tarballs, etc.

**Manual pack** (if you already have production `vendor/`):

```bash
cd apps
VERSION=X.Y.Z
tar --exclude='mobilitycheck/.git' \
    --exclude='mobilitycheck/release/mobilitycheck-*.tar.gz' \
    --exclude='mobilitycheck/tests' \
    --exclude='mobilitycheck/phpunit.xml' \
    -czf "mobilitycheck/release/mobilitycheck-${VERSION}.tar.gz" mobilitycheck
```

Do **not** commit the tarball (see app `.gitignore`).

---

## 5. Signature + checksums

**Signature** (sign the **exact** `.tar.gz` bytes you host):

```bash
openssl dgst -sha512 -sign ~/.nextcloud/certificates/mobilitycheck.key \
  /path/to/mobilitycheck-X.Y.Z.tar.gz | openssl base64
```

**Hashes** (for your records):

```bash
sha256sum mobilitycheck-X.Y.Z.tar.gz
sha512sum mobilitycheck-X.Y.Z.tar.gz
```

Optional: copy hashes into **`release/CHECKSUMS-X.Y.Z.txt`**.

---

## 6. Upload at apps.nextcloud.com

Use [upload app release](https://apps.nextcloud.com/developer/apps/releases/new). Typical fields:

| Field | Typical value |
|--------|----------------|
| **Download** | HTTPS URL to `mobilitycheck-X.Y.Z.tar.gz` |
| **Signature** | Output of `openssl dgst -sha512 -sign … .tar.gz \| openssl base64` |

Metadata is taken from the archive (`info.xml`, `CHANGELOG.md`).

---

## 7. GitHub Release — use `nextcloud-mobilitycheck`

App **tags** and **release assets** belong on **[`nextcloud-mobilitycheck`](https://github.com/aSoftwareByDesignRepository/nextcloud-mobilitycheck)** — not on the private dev monorepo.

```bash
export GH_REPO=aSoftwareByDesignRepository/nextcloud-mobilitycheck
VERSION=X.Y.Z
cd /path/to/nextcloud-mobilitycheck/release

gh release create "v${VERSION}" \
  --repo aSoftwareByDesignRepository/nextcloud-mobilitycheck \
  --title "v${VERSION}" \
  --notes-file "GITHUB_RELEASE_NOTES_${VERSION}.md" \
  "mobilitycheck-${VERSION}.tar.gz"
```

Replace asset:

```bash
gh release upload "v${VERSION}" "mobilitycheck-${VERSION}.tar.gz" \
  --repo aSoftwareByDesignRepository/nextcloud-mobilitycheck \
  --clobber
```

Source sync without tarball history: [STANDALONE_REPO.md](./STANDALONE_REPO.md).

---

## What is committed vs ignored

| Artifact | Committed? |
|----------|------------|
| `README.md`, `APPSTORE-RELEASE.md`, `STANDALONE_REPO.md`, `GITHUB_RELEASE_NOTES_*.md` | Yes |
| `CHECKSUMS-X.Y.Z.txt` | Optional |
| `*.tar.gz`, `*.tar.gz.asc` | **No** (gitignored) |
| Private key `*.key` | **Never** |

---

## Quick checklist

- [ ] `info.xml` `<version>` = changelog release version = tarball intent
- [ ] `CHANGELOG.md` has a section for that version
- [ ] Tarball top folder is **`mobilitycheck/`** only; **no `.git`**
- [ ] Download URL is **HTTPS** and points at the **same** file you signed
- [ ] `gh` release commands use **`--repo aSoftwareByDesignRepository/nextcloud-mobilitycheck`**
