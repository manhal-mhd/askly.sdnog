#!/usr/bin/env python3
"""
Generate a 30-day quiz schedule for a Moodle course.

Outputs CSV with: day, name, open_iso, close_iso, open_epoch, close_epoch

Usage:
  python3 scripts/generate_quiz_schedule.py --start-date 2026-03-10 --course-id 3 \
      --title-prefix "Day {n} – Askly Ramadan Challenge"

Note: This does not call Moodle APIs; it prepares a schedule you can use
to configure quiz open/close dates (manually, via MOOSH, or via API).
"""

import argparse
import csv
from datetime import datetime, timedelta, timezone


def parse_args():
    p = argparse.ArgumentParser(description="Generate 30-day quiz schedule")
    p.add_argument("--start-date", required=True, help="Start date in YYYY-MM-DD or YYYY-MM-DDTHH:MM format (local time)")
    p.add_argument("--course-id", required=False, help="Course ID (for reference in CSV)")
    p.add_argument("--days", type=int, default=30, help="Number of days/quizzes to generate (default 30)")
    p.add_argument("--title-prefix", default="Day {n} – Askly Ramadan Challenge",
                   help="Title template; supports {n} for day number (1-based)")
    p.add_argument("--open-hour", type=int, default=0, help="Hour of day quiz opens (0-23), default 0")
    p.add_argument("--duration-hours", type=int, default=24, help="Hours the quiz remains open, default 24")
    return p.parse_args()


def to_iso_z(dt):
    return dt.astimezone(timezone.utc).isoformat().replace("+00:00", "Z")


def main():
    args = parse_args()

    # Parse start date as local naive then localize to system TZ
    try:
        if "T" in args.start_date:
            base = datetime.strptime(args.start_date, "%Y-%m-%dT%H:%M")
        else:
            base = datetime.strptime(args.start_date, "%Y-%m-%d")
            base = base.replace(hour=args.open_hour, minute=0)
    except ValueError as e:
        raise SystemExit(f"Invalid --start-date format: {e}")

    # Treat naive as local time
    try:
        import tzlocal  # optional
        local_tz = tzlocal.get_localzone()
        base = local_tz.localize(base)
    except Exception:
        # Fallback to UTC if tzlocal not available
        base = base.replace(tzinfo=timezone.utc)

    rows = []
    for i in range(args.days):
        open_dt = base + timedelta(days=i)
        close_dt = open_dt + timedelta(hours=args.duration_hours)
        title = args.title_prefix.format(n=i + 1)
        rows.append({
            "day": i + 1,
            "course_id": args.course_id or "",
            "name": title,
            "open_iso": to_iso_z(open_dt),
            "close_iso": to_iso_z(close_dt),
            "open_epoch": int(open_dt.timestamp()),
            "close_epoch": int(close_dt.timestamp()),
        })

    writer = csv.DictWriter(
        f=__import__("sys").stdout,
        fieldnames=["day", "course_id", "name", "open_iso", "close_iso", "open_epoch", "close_epoch"],
    )
    writer.writeheader()
    for r in rows:
        writer.writerow(r)


if __name__ == "__main__":
    main()
