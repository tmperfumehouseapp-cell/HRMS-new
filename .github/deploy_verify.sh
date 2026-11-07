#!/usr/bin/env bash
# After an upload: compare a few files on the FTP server with the ones in this commit (size). Prints MATCH / DIFFERENT for each, so a deploy
# that "succeeded" but wrote into the wrong folder is visible in the log. Read-only. env: FTP_SERVER FTP_USERNAME FTP_PASSWORD [FTP_DIR] [FTP_HOST_HSTGR]
set -uo pipefail
files=(orders.html empmanagment/wa_origin.php empmanagment/api.php assets/js/wa_inbox.js)
host="$FTP_SERVER"
if ! printf '%s' "$host" | grep -Eq '\.hstgr\.io$'; then
  ip="$(getent ahostsv4 "$FTP_SERVER" 2>/dev/null | awk 'NR==1{print $1}')"
  ptr="$( (dig +short -x "$ip" 2>/dev/null || true) | head -n1 | sed 's/\.$//')"
  if printf '%s' "$ptr" | grep -Eq '^[a-z0-9.-]+\.hstgr\.io$'; then host="$ptr"; fi
  [ -n "${FTP_HOST_HSTGR:-}" ] && host="$FTP_HOST_HSTGR"
fi
dir="${FTP_DIR:-./}"
echo "server: $host   folder: $dir"
{
  echo "set net:max-retries 2"; echo "set net:timeout 25"; echo "set ftp:ssl-force true"; echo "set ftp:ssl-protect-data true"
  echo "cd \"$dir\""; echo "pwd"; echo "cls -l ."
  for f in "${files[@]}"; do echo "echo ---- $f"; echo "cls -l --time-style=long-iso \"$f\""; done
  echo "bye"
} > /tmp/verify_cmds.txt
lftp -e "source /tmp/verify_cmds.txt" -u "$FTP_USERNAME","$FTP_PASSWORD" "$host" 2>&1 | tee /tmp/verify.log | sed 's/^/   /' | head -80
for f in "${files[@]}"; do
  local_size=$(stat -c %s "$f" 2>/dev/null || echo "?")
  remote_size=$(awk -v f="$f" '$0 ~ f"$" {for(i=1;i<=NF;i++) if ($i ~ /^[0-9]+$/ && $i+0 > 100) {print $i; exit}}' /tmp/verify.log | head -1)
  if [ "$local_size" = "$remote_size" ]; then echo "MATCH      $f ($local_size bytes)"; else echo "DIFFERENT  $f  here=$local_size  server=${remote_size:-not found}"; fi
done
exit 0
