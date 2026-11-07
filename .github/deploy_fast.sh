#!/usr/bin/env bash
# Fast deploy: upload ONLY the files that changed since the last successful deploy (seconds instead of 8 minutes).
# usage: deploy_fast.sh <previous-deployed-sha>      env: FTP_SERVER FTP_USERNAME FTP_PASSWORD [FTP_DIR] [FTP_TLS=yes|no] [FTP_TLS_VERIFY=yes|no  (default yes: the server certificate is checked)]
# exit 0 = done (or nothing to do) · exit 2 = too big / unknown base, let the full upload do it · other = failed
set -euo pipefail
prev="${1:-}"
[ -n "$prev" ] && git cat-file -e "$prev^{commit}" 2>/dev/null || { echo "no usable previous deploy — full upload needed"; exit 2; }
EXCL='(^|/)\.git|^\.github/|(^|/)config\.php$|(^|/)config\.js$|(^|/)uploads/|(^|/)help_data/|(^|/)learn_backups/|(^|/)backups/|^analytics/data/|^docs/|^DEPLOY_NOTES\.md$|^README_TMPH\.md$|^gitignore$'
files=()
while IFS= read -r -d '' f; do
  printf '%s' "$f" | grep -Eq "$EXCL" && continue
  files+=("$f")
done < <(git -c core.quotepath=off diff -z --name-only --diff-filter=ACMR "$prev" HEAD)
n=${#files[@]}
echo "changed files to upload: $n"
[ "$n" -eq 0 ] && exit 0
[ "$n" -gt 150 ] && { echo "more than 150 files — full upload is safer"; exit 2; }
q() { printf '"%s"' "$(printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g')"; }
tls="${FTP_TLS:-yes}"
{
  echo "set net:max-retries 3"; echo "set net:timeout 25"; echo "set net:reconnect-interval-base 3"; echo "set cmd:fail-exit yes"
  if [ "$tls" = "yes" ]; then echo "set ftp:ssl-force true"; echo "set ftp:ssl-protect-data true"; echo "set ssl:verify-certificate ${FTP_TLS_VERIFY:-yes}"; else echo "set ftp:ssl-allow no"; fi
  [ -n "${FTP_DIR:-}" ] && [ "${FTP_DIR}" != "./" ] && echo "cd $(q "$FTP_DIR")"
  for f in "${files[@]}"; do
    d="$(dirname "$f")"
    [ "$d" != "." ] && echo "mkdir -p -f $(q "$d")"
    echo "put -O $(q "$([ "$d" = "." ] && echo . || echo "$d")") $(q "$f")"
    echo "echo uploaded: $(q "$f")"
  done
  echo "bye"
} > /tmp/lftp_cmds.txt
# The server certificate is issued for *.hstgr.io. When the FTP_SERVER secret is another name (e.g. ftp.<domain>), use the server's own
# *.hstgr.io name instead — found by reverse DNS and accepted only when it points back to the same address — so the certificate check
# (chain AND name) still passes and the fast upload works instead of the 8-minute full upload.
host="$FTP_SERVER"
if [ "$tls" = "yes" ] && ! printf '%s' "$host" | grep -Eq '\.hstgr\.io$'; then
  ip="$(getent ahostsv4 "$FTP_SERVER" 2>/dev/null | awk 'NR==1{print $1}')"
  if [ -n "$ip" ]; then
    ptr="$( (dig +short -x "$ip" 2>/dev/null || true) | head -n1 | sed 's/\.$//')"; [ -z "$ptr" ] && ptr="$(getent hosts "$ip" 2>/dev/null | awk '{print $2}' | head -n1)"
    if printf '%s' "$ptr" | grep -Eq '^[a-z0-9.-]+\.hstgr\.io$' && getent ahostsv4 "$ptr" 2>/dev/null | awk '{print $1}' | grep -qx "$ip"; then host="$ptr"; echo "using the certificate's host name for this server: $host"; fi
    if [ "$host" = "$FTP_SERVER" ]; then for c in "${FTP_HOST_HSTGR:-}"; do [ -n "$c" ] && getent ahostsv4 "$c" 2>/dev/null | awk '{print $1}' | grep -qx "$ip" && host="$c"; done; fi
  fi
fi
lftp -e "source /tmp/lftp_cmds.txt" -u "$FTP_USERNAME","$FTP_PASSWORD" -p "${FTP_PORT:-21}" "$host"
echo "fast deploy finished: $n file(s)"
