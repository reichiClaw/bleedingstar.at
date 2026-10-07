#!/usr/bin/env python3
"""Write data/content.json from the WordPress table dumps in $BS_RAW.

Keeps page and catalog content. Leaves out injected spam posts, user
accounts, and option values that hold passwords or license keys.
"""
from __future__ import annotations

import json
import os
import urllib.parse
from collections import defaultdict

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
RAW = os.environ.get("BS_RAW", "/tmp/bs/export")
OUT = os.path.join(ROOT, "data", "content.json")
SITE = "https://www.bleedingstar.at"

CONTENT_TYPES = ("page", "artist", "release", "event", "post", "document", "video")
SKIP_META_PREFIXES = (
    "_yoast",
    "_wpas",
    "avada_",
    "_jetpack",
    "_wpcode",
    "_fusion",
    "_oembed",
    "_edit_",
    "_publicize",
    "_enclose",
    "_pingme",
    "_wp_old_",
    "_wp_desired_",
    "_wp_trash",
    "_thumbnail_id" if False else "___never",
)
KEEP_PRIVATE_META = {"_thumbnail_id", "_wp_attached_file", "_wp_page_template"}


def load(name):
    with open(os.path.join(RAW, name), encoding="utf-8") as handle:
        return json.load(handle)


def keep_meta(key):
    if key in KEEP_PRIVATE_META or key.startswith("_menu_item_"):
        return True
    if key.startswith("_"):
        return False
    for prefix in SKIP_META_PREFIXES:
        if key.startswith(prefix):
            return False
    return True


def upload_url(path):
    if not path:
        return None
    if path.startswith("http://") or path.startswith("https://"):
        return path
    return SITE + "/wp-content/uploads/" + urllib.parse.quote(path)


def sidebar_content(options):
    """Booking text and the release-page badges, keyed the way the theme stored them."""
    try:
        import phpserialize
    except ImportError:
        return []
    raw_text = options.get("widget_text") or ""
    raw_map = options.get("themex_ThemexWidgetiser") or ""
    raw_sidebars = options.get("sidebars_widgets") or ""
    if not raw_text or not raw_map or not raw_sidebars:
        return []
    texts = phpserialize.loads(raw_text.encode(), decode_strings=True)
    groups = phpserialize.loads(raw_map.encode(), decode_strings=True)
    bars = phpserialize.loads(raw_sidebars.encode(), decode_strings=True)
    images = phpserialize.loads((options.get("widget_media_image") or "a:0:{}").encode(), decode_strings=True)
    text_by_id = {}
    for key, val in texts.items():
        if isinstance(val, dict) and val.get("text"):
            text_by_id[f"text-{key}"] = {
                "title": val.get("title") or "",
                "text": val.get("text") or "",
            }
    for key, val in images.items():
        if isinstance(val, dict) and val.get("url"):
            text_by_id[f"media_image-{key}"] = {
                "title": val.get("title") or "",
                "text": "",
                "image": val.get("url") or "",
                "link": val.get("link_url") or "",
            }
    ordered = [val for val in groups.values() if isinstance(val, dict)]
    out = []
    for index, group in enumerate(ordered):
        widgets = bars.get(f"sidebar-{index + 2}") or []
        if isinstance(widgets, dict):
            widgets = list(widgets.values())
        blocks = []
        for widget_id in widgets:
            block = text_by_id.get(str(widget_id))
            if block:
                blocks.append(block)
            # custom-html widgets on these sidebars were injected placeholders, not content.
        pages = [str(pid) for pid in (group.get("pages") or {}).values()]
        if not blocks and not pages:
            continue
        out.append(
            {
                "name": group.get("name") or "",
                "description": group.get("description") or "",
                "page_ids": pages,
                "widgets": blocks,
            }
        )
    return out


def main():
    posts = load("wp_posts.json")
    meta_rows = load("wp_postmeta.json")
    terms = load("wp_terms.json")
    tax = load("wp_term_taxonomy.json")
    rel = load("wp_term_relationships.json")
    options = {row["option_name"]: row["option_value"] for row in load("wp_options.json")}

    meta = defaultdict(dict)
    for row in meta_rows:
        if keep_meta(row["meta_key"]):
            meta[row["post_id"]][row["meta_key"]] = row["meta_value"]

    by_id = {post["ID"]: post for post in posts}
    term_by = {term["term_id"]: term for term in terms}
    tax_by = {row["term_taxonomy_id"]: row for row in tax}

    categories = defaultdict(list)
    for row in rel:
        tx = tax_by.get(row["term_taxonomy_id"])
        if not tx or tx["taxonomy"] != "artist_category":
            continue
        term = term_by.get(tx["term_id"])
        if term:
            categories[row["object_id"]].append(
                {"name": term["name"], "slug": term["slug"]}
            )

    def record(post):
        item = {
            "id": post["ID"],
            "slug": post["post_name"],
            "title": post["post_title"],
            "status": post["post_status"],
            "date": post["post_date"],
            "modified": post["post_modified"],
            "parent": post["post_parent"],
            "order": post["menu_order"],
            "content": post["post_content"] or "",
            "excerpt": post["post_excerpt"] or "",
            "meta": meta.get(post["ID"], {}),
        }
        thumb = item["meta"].get("_thumbnail_id")
        if thumb and thumb in by_id:
            attached = meta.get(thumb, {}).get("_wp_attached_file")
            if attached:
                item["image"] = upload_url(attached)
        profile = item["meta"].get("artist_profile_image")
        if profile:
            item["profile_image"] = profile if profile.startswith("http") else upload_url(profile)
        if post["post_type"] == "artist":
            item["categories"] = categories.get(post["ID"], [])
        return item

    grouped = {key: [] for key in ("pages", "artists", "releases", "events", "news", "documents", "videos")}
    type_to_group = {
        "page": "pages",
        "artist": "artists",
        "release": "releases",
        "event": "events",
        "post": "news",
        "document": "documents",
        "video": "videos",
    }
    spam = 0
    for post in posts:
        if post["post_type"] not in CONTENT_TYPES:
            continue
        if post["post_status"] in ("auto-draft", "inherit"):
            continue
        if post["post_type"] == "post" and post["post_date"] >= "2020-01-01":
            spam += 1
            continue
        grouped[type_to_group[post["post_type"]]].append(record(post))

    for key in grouped:
        grouped[key].sort(key=lambda item: (item["date"], int(item["id"])))

    # Menu fields are stored on the menu item, which is not one of the content types.
    menu_meta = defaultdict(dict)
    for row in meta_rows:
        if str(row["meta_key"]).startswith("_menu_item_"):
            menu_meta[row["post_id"]][row["meta_key"]] = row["meta_value"]

    menu = []
    for post in posts:
        if post["post_type"] != "nav_menu_item" or post["post_status"] != "publish":
            continue
        fields = menu_meta.get(post["ID"], {})
        target_id = fields.get("_menu_item_object_id")
        target = by_id.get(target_id)
        menu.append(
            {
                "order": int(post["menu_order"] or 0),
                "title": (target or {}).get("post_title") or post["post_title"],
                "object_id": target_id,
                "slug": (target or {}).get("post_name"),
            }
        )
    menu.sort(key=lambda item: item["order"])

    attachments = []
    for post in posts:
        if post["post_type"] != "attachment":
            continue
        path = meta.get(post["ID"], {}).get("_wp_attached_file")
        attachments.append(
            {
                "id": post["ID"],
                "title": post["post_title"],
                "mime": post.get("post_mime_type") or "",
                "parent": post["post_parent"],
                "file": path,
                "url": upload_url(path) if path else post.get("guid"),
            }
        )
    attachments.sort(key=lambda item: int(item["id"]))

    # Current published file for each document is the attachment id stored as content.
    for doc in grouped["documents"]:
        attached = by_id.get((doc["content"] or "").strip())
        if not attached:
            continue
        path = meta.get(attached["ID"], {}).get("_wp_attached_file")
        doc["file"] = {
            "id": attached["ID"],
            "title": attached["post_title"],
            "mime": attached.get("post_mime_type") or "",
            "url": upload_url(path) if path else attached.get("guid"),
        }

    sidebars = sidebar_content(options)

    payload = {
        "site": {
            "name": options.get("blogname") or "BleedingStar",
            "home": options.get("home") or SITE,
            "language": options.get("WPLANG") or "de_DE",
            "theme": options.get("stylesheet") or "replay",
            "permalink": options.get("permalink_structure") or "",
            "show_on_front": options.get("show_on_front"),
            "front_page_id": options.get("page_on_front"),
            "copyright": "© BleedingStar Music Services",
            "primary_color": "#EE3450",
            "logo": options.get("themex_logo_image"),
            "favicon": options.get("themex_favicon"),
            "menu": menu,
            "sidebars": sidebars,
        },
        "counts": {
            "pages": len(grouped["pages"]),
            "artists": len(grouped["artists"]),
            "releases": len(grouped["releases"]),
            "events": len(grouped["events"]),
            "news": len(grouped["news"]),
            "documents": len(grouped["documents"]),
            "videos": len(grouped["videos"]),
            "attachments": len(attachments),
            "excluded_spam_posts": spam,
        },
        **grouped,
        "attachments": attachments,
    }

    os.makedirs(os.path.dirname(OUT), exist_ok=True)
    with open(OUT, "w", encoding="utf-8") as handle:
        json.dump(payload, handle, ensure_ascii=False, indent=2)
        handle.write("\n")
    print("wrote", OUT, "spam_excluded", spam)


if __name__ == "__main__":
    main()
