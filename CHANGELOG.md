# Changelog

Alle nennenswerten Änderungen an diesem Plugin.
Versionsschema nach [Semantic Versioning](https://semver.org/lang/de/): Bugfixes erhöhen die Patch-Stelle (`0.4.x`), größere Änderungen die Minor-Stelle (`0.x.0`).

## 0.4.7
- Dashboard: 24h-Option im Traffic-Dropdown
- Traffic-Zeitraum berechnet echte Summen (letzte N Tage aus Tagesdaten) statt nur das Label zu ändern
- Cache period-unabhängig (kein Doppel-Fetch beim Umschalten)

## 0.4.6
- Detail-Schrift an native Unraid-Tiles angeglichen (Sans-Serif statt Monospace, hellere Labels)

## 0.4.5
- IP-Adresse exakt unter dem Titel via einspaltigem Flexbox-Layout

## 0.4.4
- rowspan zurückgenommen (zerschoss das Tile-Layout), Icon wieder inline

## 0.4.3
- (fehlerhafter rowspan-Versuch)

## 0.4.2
- Typ (DG-Gateway) aus der eingeklappten Mini-Zeile entfernt

## 0.4.1
- IP-Adresse kompakt unter der Überschrift

## 0.4.0
- Fix: Update-Mechanismus repariert — Versions-Stub entfernt, volle .plg bleibt von Unraid verwaltet
- Dashboard-Icon 32x32

## 0.3.x
- Dashboard-Kachel (mehrere Iterationen bis zum nativen Unraid-Schema via `$mytiles` + `<tbody>`)
- Native Unraid-Buttons (fa-cog / fa-chevron), Texte in Dashboard-Standardfarbe
- Dashboard-Einstellungen (Refresh-Intervall + Traffic-Zeitraum)

## 0.2.0
- Farbpalette an Unraid-UI angepasst
- Icon überarbeitet (Globus mit K)

## 0.1.x
- Feste-IP-ID in Übersicht, robuste Feld-Erkennung (findId/findIp/findField)
- Traffic-Tabelle mit automatischer Feldnamen-Erkennung
- Fix: GET-basierte API-Kommunikation (Unraid 7.x nginx-Kompatibilität)
- DG-WAN-IP Action `dgwan_manage`

## 0.1.0
- Initiales Release
- Feste IPs auflisten, Details, Reverse-DNS, Traffic-Graph/-Tabelle, Port-Scanner
