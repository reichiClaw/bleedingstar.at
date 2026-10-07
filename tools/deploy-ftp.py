#!/usr/bin/env python3
"""Deploy this repository to the World4You web root via FTPS.

Not needed on the server. Python 3.8+, standard library only.

    python3 tools/deploy-ftp.py list                      # recursive listing of the server
    python3 tools/deploy-ftp.py diff                      # compare server with the deploy tree
    python3 tools/deploy-ftp.py deploy --dry-run          # show what would be uploaded
    python3 tools/deploy-ftp.py deploy                    # upload what differs, verify sizes
    python3 tools/deploy-ftp.py deploy --config FILE      # also upload FILE as app/config/config.php
                                                          # when the server has none yet

Credentials come from the environment: bleedingstar_FTP_HOST, bleedingstar_FTP_USER,
bleedingstar_FTP_PASS (the Cloud Agent secrets of the same name). The FTP root is the web root.

Server layout (the FTP root is the document root, so nothing can live above it):

    /                      <- public/            front controller, assets, .htaccess
    /app/                  <- src/, templates/, config/, bin/, database/, data/, storage/
    /app/.htaccess         <- deploy/app.htaccess      (denies every direct request)
    /wp-content/.htaccess  <- deploy/wp-uploads.htaccess (old media stays static-only)

Rules:
  - the server is listed and compared first; `diff` and `--dry-run` never write;
  - files with the same size are compared by SHA-256 (downloaded), so a same-size edit is
    still detected and an unchanged file is never re-uploaded;
  - app/config/config.php is never overwritten; runtime data in app/storage/logs|locks|jobs|cache
    and provider covers (storage/uploads/covers/discogs|deezer, fetched by the server's own jobs)
    are never written; other files under app/storage/uploads/ are only added, never replaced;
  - nothing is ever deleted - files that exist only on the server are reported;
  - each upload goes to <name>.uploading~ first and is renamed into place; static files first,
    PHP last; afterwards the touched directories are re-listed and sizes verified.

Host quirks handled here (World4You, Pure-FTPd):
  - TLS 1.3 data connections are reset by the server, so TLS is capped at 1.2;
  - the server refuses passive data connections whose source IP differs from the control
    connection (anti-FXP), closes them and keeps waiting. From a network that leaves through
    a NAT pool (several public IPs chosen per TCP connection, e.g. a cloud VM) stock clients
    therefore hang and leave sessions open until the "8 connections as the same user" limit
    hits. This client reconnects to the same passive port until the server accepts the data
    connection (signalled by the 150 reply on the control channel).
"""
import argparse
import hashlib
import json
import os
import select
import socket
import ssl
import sys
import time
from ftplib import FTP_TLS, error_perm, error_reply, error_temp

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SECRET_PREFIX = "bleedingstar_FTP_"

# repo directory -> server directory
MAPPING = [
    ("public", "/"),
    ("src", "/app/src"),
    ("templates", "/app/templates"),
    ("config", "/app/config"),
    ("bin", "/app/bin"),
    ("database", "/app/database"),
    ("data", "/app/data"),
    ("storage", "/app/storage"),
]
EXTRA_FILES = [
    ("deploy/app.htaccess", "/app/.htaccess"),
    ("deploy/wp-uploads.htaccess", "/wp-content/.htaccess"),
    ("deploy/wp-uploads.htaccess", "/wp-content/uploads/.htaccess"),
]
# Local files that must never reach the server.
LOCAL_SKIP = {"/router.php", "/app/config/config.php", "/app/storage/install.done"}
LOCAL_SKIP_NAMES = {".DS_Store"}

CONFIG_REMOTE = "/app/config/config.php"
RUNTIME_PREFIX = ("/app/storage/logs/", "/app/storage/locks/", "/app/storage/jobs/", "/app/storage/cache/",
                  # provider covers are fetched by the catalogue jobs on the server itself
                  "/app/storage/uploads/covers/discogs/", "/app/storage/uploads/covers/deezer/")
ADD_ONLY_PREFIX = ("/app/storage/uploads/",)

MAX_DATA_TRIES = 400


class NatPoolFTPS(FTP_TLS):
    def __init__(self, host, user, password, timeout=60, verbose=False):
        ctx = ssl.create_default_context()
        ctx.maximum_version = ssl.TLSVersion.TLSv1_2
        super().__init__(context=ctx, timeout=timeout)
        self.verbose = verbose
        self.data_tries = 0
        self.transfers = 0
        self.connect(host, 21)
        self.auth()
        self.login(user, password)
        self.prot_p()
        self.set_pasv(True)

    def ntransfercmd(self, cmd, rest=None):
        host, port = self.makepasv()
        if rest is not None:
            self.sendcmd("REST %s" % rest)
        # Send the command first so the server enters its accept loop.
        self.putcmd(cmd)
        ctrl = self.sock
        tries = 0
        while True:
            tries += 1
            self.data_tries += 1
            if tries > MAX_DATA_TRIES:
                raise error_temp("data connection never accepted after %d tries" % MAX_DATA_TRIES)
            conn = socket.create_connection((host, port), self.timeout, source_address=self.source_address)
            # Whoever speaks first decides: control -> 150 (accepted); data -> EOF (rejected).
            readable, _, _ = select.select([ctrl, conn], [], [], self.timeout)
            if not readable:
                conn.close()
                raise error_temp("data connection: no reaction from server")
            if conn in readable and ctrl not in readable:
                try:
                    peek = conn.recv(1, socket.MSG_PEEK)
                except OSError:
                    peek = b""
                if peek == b"":
                    conn.close()
                    time.sleep(0.05)
                    continue
            resp = self.getresp()
            if resp[0] == "2":
                resp = self.getresp()
            if resp[0] != "1":
                conn.close()
                raise error_reply(resp)
            break
        if self.verbose and tries > 1:
            print(f"    data connection accepted after {tries} tries", file=sys.stderr)
        self.transfers += 1
        if self._prot_p:
            conn = self.context.wrap_socket(conn, server_hostname=self.host, session=self.sock.session)
        return conn, None

    def listdir(self, path):
        out = []
        for name, facts in self.mlsd(path):
            if name in (".", ".."):
                continue
            size = int(facts.get("size", facts.get("sizd", 0)) or 0)
            out.append((name, facts.get("type"), size, facts.get("modify"), facts.get("unix.mode")))
        return sorted(out, key=lambda e: (e[1] != "dir", e[0].lower()))

    def walk(self, path="/", skip=()):
        for name, kind, size, modified, mode in self.listdir(path):
            full = path.rstrip("/") + "/" + name
            yield full, kind, size, modified, mode
            if kind == "dir" and full not in skip:
                yield from self.walk(full, skip)

    def download_bytes(self, path):
        buf = bytearray()
        self.retrbinary("RETR " + path, buf.extend)
        return bytes(buf)

    def upload_bytes(self, data, remote):
        import io
        tmp = remote + ".uploading~"
        self.storbinary("STOR " + tmp, io.BytesIO(data))
        try:
            self.rename(tmp, remote)
        except error_perm:
            self.delete(remote)
            self.rename(tmp, remote)
        return len(data)

    def upload_file(self, local, remote):
        with open(local, "rb") as fh:
            return self.upload_bytes(fh.read(), remote)

    def quit_quiet(self):
        try:
            self.quit()
        except Exception:
            self.close()


def connect(verbose=False):
    try:
        host, user, password = (os.environ[SECRET_PREFIX + k] for k in ("HOST", "USER", "PASS"))
    except KeyError as e:
        sys.exit(f"missing environment variable {e}")
    ftp = NatPoolFTPS(host, user, password, verbose=verbose)
    print(f"# connected to {host} (FTPS, TLS 1.2), root = {ftp.pwd()}")
    return ftp


def local_tree():
    """-> {remote_path: local_path} for everything this repository deploys."""
    files = {}
    for local_dir, remote_dir in MAPPING:
        base = os.path.join(ROOT, local_dir)
        for root, _, names in os.walk(base):
            for name in names:
                if name in LOCAL_SKIP_NAMES:
                    continue
                path = os.path.join(root, name)
                rel = os.path.relpath(path, base).replace(os.sep, "/")
                remote = remote_dir.rstrip("/") + "/" + rel
                if remote in LOCAL_SKIP:
                    continue
                files[remote] = path
    for local_file, remote in EXTRA_FILES:
        files[remote] = os.path.join(ROOT, local_file)
    return files


# Directories on the server that are not part of this project and are large.
# They are listed one level deep only, so `list` and `diff` stay fast.
# Listed one level deep only: legacy applications that this project never touches, the old
# WordPress media folder (kept for old links), the archive and our own upload tree.
SHALLOW = {"/wp-content", "/wp-content/uploads", "/wp-admin", "/wp-includes", "/files", "/fileserver",
           "/downloadserver", "/download", "/stats", "/imunify-antivirus", "/_archiv-alt",
           "/app/storage/uploads", "/.tmb", "/bt", "/caldav", "/coupon", "/fb", "/intranet",
           "/mailoffer", "/music", "/new", "/release", "/shop", "/stream", "/webmail",
           "/webmailnew", "/webshop"}


def server_tree(ftp, deep_uploads=False):
    files, dirs = {}, set()
    skip = set(SHALLOW)
    if deep_uploads:
        skip.discard("/app/storage/uploads")
    for full, kind, size, _, _ in ftp.walk("/", skip=skip):
        if kind == "dir":
            dirs.add(full)
        else:
            files[full] = size
    return files, dirs


def sha256(data):
    return hashlib.sha256(data).hexdigest()


def is_runtime(remote):
    return remote.startswith(RUNTIME_PREFIX) and not remote.endswith(".gitkeep")


def compare(ftp, local, server, cache):
    """-> (to_upload [(remote, reason)], identical [remote], skipped [(remote, why)], server_only)"""
    to_upload, identical, skipped = [], [], []
    for remote in sorted(local):
        if remote == CONFIG_REMOTE:
            skipped.append((remote, "config is never overwritten"))
            continue
        if is_runtime(remote):
            skipped.append((remote, "runtime data"))
            continue
        if remote not in server and os.path.dirname(remote) in SHALLOW:
            # Inside a directory the walk did not enter: ask for the size directly.
            try:
                server[remote] = ftp.size(remote) or 0
            except error_perm:
                pass
        if remote not in server:
            to_upload.append((remote, "new"))
            continue
        if remote.startswith(ADD_ONLY_PREFIX):
            skipped.append((remote, "upload exists, add-only"))
            continue
        if server[remote] != os.path.getsize(local[remote]):
            to_upload.append((remote, "size"))
            continue
        if cache.get(remote, {}).get("size") != server[remote]:
            cache[remote] = {"size": server[remote], "sha256": sha256(ftp.download_bytes(remote))}
        with open(local[remote], "rb") as fh:
            if cache[remote]["sha256"] == sha256(fh.read()):
                identical.append(remote)
            else:
                to_upload.append((remote, "content"))
        done = len(to_upload) + len(identical)
        if done % 25 == 0:
            print(f"  ... compared {done}/{len(local)}", file=sys.stderr)
    server_only = sorted(p for p in server if p not in local)
    return to_upload, identical, skipped, server_only


def cmd_list(args):
    ftp = connect(args.verbose)
    n = 0
    for full, kind, size, modified, mode in ftp.walk("/", skip=SHALLOW if not args.deep else ()):
        n += 1
        if kind == "dir":
            print(f"D {mode or '-':>6} {'':>9} {modified or '-'} {full}/")
        else:
            print(f"F {mode or '-':>6} {size:>9} {modified or '-'} {full}")
    ftp.quit_quiet()
    print(f"# {n} entries, {ftp.transfers} transfers, {ftp.data_tries} data connects", file=sys.stderr)


def report(to_upload, identical, skipped, server_only, local, server):
    print(f"\n# identical (verified by SHA-256): {len(identical)}")
    print(f"# skipped (config, runtime, existing uploads): {len(skipped)}")
    print(f"# to upload: {len(to_upload)}")
    for remote, why in to_upload:
        extra = "" if why == "new" else f"  (server {server[remote]} B, local {os.path.getsize(local[remote])} B)"
        print(f"   {why:7} {remote}{extra}")
    print(f"# only on the server (never deleted by this script): {len(server_only)}")
    for remote in server_only:
        print(f"   -       {remote}  {server[remote]} B")


def ensure_dirs(ftp, dirs, remote):
    parent, parts = "", os.path.dirname(remote).strip("/").split("/")
    for part in [p for p in parts if p]:
        parent += "/" + part
        if parent not in dirs:
            try:
                ftp.mkd(parent)
                print(f"  mkdir    {parent}")
            except error_perm:
                pass
            dirs.add(parent)


def cmd_diff(args, do_upload=False):
    ftp = connect(args.verbose)
    local = local_tree()
    server, dirs = server_tree(ftp, deep_uploads=True)
    print(f"# local files: {len(local)}, server files: {len(server)}")
    cache_file = os.path.join(ROOT, ".deploy-ftp-cache.json")
    cache = {}
    if os.path.exists(cache_file):
        with open(cache_file) as fh:
            cache = json.load(fh)
    try:
        to_upload, identical, skipped, server_only = compare(ftp, local, server, cache)
    finally:
        with open(cache_file, "w") as fh:
            json.dump(cache, fh)
    report(to_upload, identical, skipped, server_only, local, server)

    config_upload = None
    if getattr(args, "config", None):
        if CONFIG_REMOTE in server:
            print(f"# {CONFIG_REMOTE} exists on the server and stays untouched")
        else:
            config_upload = args.config
            print(f"# {CONFIG_REMOTE} is missing; {args.config} will be uploaded")

    if not do_upload or args.dry_run:
        ftp.quit_quiet()
        print("\n# nothing uploaded" + (" (dry run)" if do_upload else ""))
        return

    # Static files first, PHP last, so a half-finished run never references missing assets.
    uploaded = []
    order = sorted(to_upload, key=lambda x: (x[0].endswith(".php"), x[0]))
    for remote, why in order:
        ensure_dirs(ftp, dirs, remote)
        size = ftp.upload_file(local[remote], remote)
        with open(local[remote], "rb") as fh:
            cache[remote] = {"size": size, "sha256": sha256(fh.read())}
        uploaded.append((remote, size))
        print(f"  uploaded {remote} ({size} B, {why})")
    if config_upload:
        ensure_dirs(ftp, dirs, CONFIG_REMOTE)
        size = ftp.upload_file(config_upload, CONFIG_REMOTE)
        uploaded.append((CONFIG_REMOTE, size))
        print(f"  uploaded {CONFIG_REMOTE} ({size} B, config)")
    with open(cache_file, "w") as fh:
        json.dump(cache, fh)

    print("\n# verification")
    ok = True
    for directory in sorted({os.path.dirname(r) or "/" for r, _ in uploaded}):
        names = {n: s for n, kind, s, _, _ in ftp.listdir(directory) if kind == "file"}
        for remote, size in uploaded:
            if os.path.dirname(remote) == directory:
                got = names.get(os.path.basename(remote))
                ok &= got == size
                print(f"  {'ok' if got == size else f'MISMATCH (server {got} B)':>10}  {remote}")
        leftovers = [n for n in names if n.endswith(".uploading~")]
        if leftovers:
            ok = False
            print(f"  temp files left in {directory}: {leftovers}")
    ftp.quit_quiet()
    print(f"\n# {len(uploaded)} files uploaded, {ftp.transfers} transfers, {ftp.data_tries} data connects, "
          f"all verified: {ok}")
    sys.exit(0 if ok else 1)


def main():
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("-v", "--verbose", action="store_true", help="report data-connection retries")
    sub = parser.add_subparsers(dest="command", required=True)
    lst = sub.add_parser("list", help="recursive listing of the server")
    lst.add_argument("--deep", action="store_true", help="also descend into large media folders")
    sub.add_parser("diff", help="compare server with the deploy tree (read-only)")
    deploy = sub.add_parser("deploy", help="upload what differs")
    deploy.add_argument("--dry-run", action="store_true", help="compare only, upload nothing")
    deploy.add_argument("--config", help="local config.php to upload only if the server has none")
    args = parser.parse_args()
    if args.command == "list":
        cmd_list(args)
    elif args.command == "diff":
        args.dry_run = True
        cmd_diff(args)
    else:
        cmd_diff(args, do_upload=True)


if __name__ == "__main__":
    main()
