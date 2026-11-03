#!/usr/bin/env python3
"""Writes fixtures/month-starts.txt from Swiss Ephemeris alone, as the oracle
the PHP must match: every Kerala solar month start from 1900 to 2100.

    python3 -m venv /tmp/v && /tmp/v/bin/pip install pyswisseph
    /tmp/v/bin/python tests/make_fixture.py

Each line: first day (Y-m-d), sign (0 = Mesha), Kali Yuga year, the
sankranti in IST, and how many minutes it fell after the Kerala cutoff
(negative = before, so the month begins that day). Sankrantis come from
swe.solcross_ut on the Lahiri sidereal zodiac, sunrise and sunset from
swe.rise_trans at Thiruvananthapuram (upper limb, standard refraction).
"""
import os
import swisseph as swe

LAT, LON = 8.5241, 76.9366
IST = 5.5 / 24
HERE = os.path.dirname(os.path.abspath(__file__))

swe.set_sid_mode(swe.SIDM_LAHIRI)
FLAGS = swe.FLG_MOSEPH | swe.FLG_SIDEREAL


def ist_civil(jd):
    return int(jd + IST + 0.5) - 0.5          # 0h UT of the IST date (positive JDs)


def ymd(jd):
    y, m, d, _ = swe.revjul(jd + 0.5, swe.GREG_CAL)
    return y, m, d


def cutoff(date):
    start = date - IST                         # IST midnight
    geo = (LON, LAT, 0)
    rise = swe.rise_trans(start, swe.SUN, swe.CALC_RISE, geo, 1013.25, 27, swe.FLG_MOSEPH)[1][0]
    set_ = swe.rise_trans(start, swe.SUN, swe.CALC_SET, geo, 1013.25, 27, swe.FLG_MOSEPH)[1][0]
    return rise + 0.6 * (set_ - rise)


rows = []
jd = swe.julday(1899, 12, 1, 0)
end = swe.julday(2101, 1, 1, 0)
kali = None
while jd < end:
    lon = swe.calc_ut(jd, swe.SUN, FLAGS)[0][0]
    sign = (int(lon // 30) + 1) % 12
    t = swe.solcross_ut(30 * sign, jd, FLAGS)
    date = ist_civil(t)
    margin = (t - cutoff(date)) * 1440
    first = date + (1 if margin > 0 else 0)
    y, m, d = ymd(first)
    if sign == 0:
        kali = y + 3101
    if kali is not None and y >= 1900:
        hh = (t + IST + 0.5) % 1 * 24
        rows.append('%04d-%02d-%02d %2d %d %02d:%02d %+8.2f' % (y, m, d, sign, kali, int(hh), int(hh % 1 * 60), margin))
    jd = t + 25

out = os.path.join(HERE, 'fixtures', 'month-starts.txt')
with open(out, 'w') as f:
    f.write('# first-day sign kali sankranti-IST minutes-after-cutoff  (Swiss Ephemeris, Lahiri, Thiruvananthapuram)\n')
    f.write('\n'.join(rows) + '\n')
print(out, len(rows), 'month starts')
