# CLAUDE.md — Metro North Live Train Board

> Read this file completely before doing anything in this project.
> This is your single source of truth for every decision you make.

---

## 🧠 What This Project Is

A live Metro North Railroad departure board for a restaurant in Stratford, Connecticut.
It displays real-time train departures on a large wall-mounted TV inside the restaurant,
and also embeds on the restaurant's Squarespace website via iframe.

**Client:** deep6arcadellc
**Restaurant location:** Stratford, Connecticut
**Client branding:** NOT YET RECEIVED — logo, colors, and visual references are pending

---

## 🚫 Scope Boundaries — Never Cross These

- No login system
- No admin panel
- No dashboard
- No database tables
- No user accounts
- No extra pages beyond /board and /embed
- No extra API routes beyond /api/board and /api/alerts
- No calling MTA directly from the frontend — ever
- Do not poll MTA faster than every 30 seconds — it only updates every 30 sec server-side
- Do not use google/gtfs-realtime-bindings — it is deprecated since 2019
- Do not use Shore Line East or CTrail feeds — Shore Line East discontinued Spring 2024

---

## 📁 Project Structure

```
metro-north-board/
│
├── backend/                        # Laravel PHP
│   ├── app/
│   │   ├── Services/
│   │   │   └── MetroNorthService.php   # Core MTA fetcher + decoder + cacher
│   │   └── Console/
│   │       └── Kernel.php              # Scheduled polling every 30 sec
│   ├── routes/
│   │   └── api.php                     # /api/board + /api/alerts only
│   └── .env                            # STRATFORD_STOP_ID lives here
│
├── frontend/                       # React JS
│   └── src/
│       ├── pages/
│       │   ├── Board.jsx               # Main TV fullscreen display
│       │   └── Embed.jsx               # Lightweight Squarespace embed
│       ├── components/
│       │   ├── FlipBoard.jsx           # Vesta split-flap animation wrapper
│       │   ├── TrainRow.jsx            # Single train row component
│       │   └── SectionHeader.jsx      # Section label component
│       └── hooks/
│           └── useTrainData.js         # Polls /api/board every 15 seconds
│
└── CLAUDE.md                       # ← You are here
```

---

## 🖥️ Pages — Only 2 Frontend Pages

### Page 1: `/board` (also `/`)
- Primary deliverable
- Fullscreen, optimized for 1920x1080 TV wall display
- Vesta Board flip animation style
- Two sections: → New Haven and → NYC
- Auto-refreshes every 15 seconds
- No buttons, no user interaction required

### Page 2: `/embed`
- Lightweight version of the same board
- Same live data, smaller layout
- Designed to sit inside a Squarespace iframe
- Must look good at all responsive sizes

---

## 🔌 API Routes — Only 2 Backend Routes

### GET /api/board
Returns next 3 trains toward New Haven + next 3 trains toward NYC
Response shape:
```
{
  "to_new_haven": [ { train, time, status }, ... ],  // 3 items
  "to_nyc":       [ { train, time, status }, ... ]   // 3 items
}
```

### GET /api/alerts
Returns active Metro North service alerts
Used to detect and display cancelled trains

---

## 📊 Data Displayed on the Board

### Section 1 — Stratford CT → New Haven CT
- Show next 3 upcoming departures only
- Each row: Train Number · Departure Time · Status

### Section 2 — Stratford CT → New York City
- Show next 3 upcoming departures only
- Each row: Train Number · Departure Time · Status

### Status Values (only these three)
- On Time       → when delay = 0
- Delayed X min → when delay > 0, show minutes (round delay_seconds / 60)
- Cancelled     → when trip appears in alerts feed, not in trip updates

---

## 🎨 Design Requirements

| Property         | Requirement                                              |
|------------------|----------------------------------------------------------|
| Style            | Classic Vesta Board / Split-Flap flip animation          |
| Background       | Black or very dark navy                                  |
| Text color       | Amber or white                                           |
| Font feel        | Monospace, terminal, old train station                   |
| Primary target   | 1920x1080 Full HD TV wall display                        |
| Responsive       | TV · Desktop · Tablet · Mobile — all must look great     |
| Branding         | Pending — client to send logo and colors                 |
| Quality bar      | Premium, impressive, professional — not a basic table    |

### Responsive Breakpoints
| Screen          | Layout behavior                                          |
|-----------------|----------------------------------------------------------|
| TV (1920x1080)  | Fullscreen · two sections side by side · large text      |
| Desktop 1200px+ | Same layout scaled down · still looks like departure board|
| Tablet 768-1199 | Single column · sections stacked vertically              |
| Mobile <768px   | Compact · stacked · font sizes adjusted · fully usable   |

Every screen size must look impressive. Nothing broken or squeezed.

---

## 🌐 MTA Data Source

### No API Key Needed
Feeds are 100% free and public. No registration. No account.

### Three URLs

| Purpose           | URL                                                                                      | Format          |
|-------------------|------------------------------------------------------------------------------------------|-----------------|
| Live train data   | https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/mnr/gtfs-mnr                     | Binary Protobuf |
| Service alerts    | https://api-endpoint.mta.info/Dataservice/mtagtfsfeeds/camsys/mnr-alerts.json           | JSON            |
| Static schedule   | https://rrgtfsfeeds.s3.amazonaws.com/gtfsmnr.zip                                         | Zipped CSVs     |

### Composer Package
Use: lowa/gtfs-realtime-php
Never use: google/gtfs-realtime-bindings (deprecated 2019)

---

## 🔑 Critical Technical Facts

### Stratford Stop ID
- Must be found once from gtfsmnr.zip → stops.txt → search "Stratford"
- Expected to be in the 120s–150s range
- Save in .env as STRATFORD_STOP_ID
- All filtering logic depends on this number — find it before writing any filter code

### New Haven Line
- Route ID = 3 in MTA static GTFS data

### Train Direction
- direction_id = 0 → toward New Haven (east)
- direction_id = 1 → toward New York City (west)
- Get direction_id from trips.txt inside the GTFS zip

### Join Key for Static + Realtime Data
- Do NOT use trip_id to join — it does not always match
- Use vehicle.label matched against trip_short_name in trips.txt

### Delay / Status Logic
- delay = 0 → On Time
- delay > 0 → Delayed (round delay/60 to get minutes)
- Cancellations come from alerts feed, NOT trip updates feed

### Caching
- Cache MTA responses for 20 seconds
- Poll MTA every 30 seconds via Laravel scheduler
- Use Laravel file cache or Redis

### Polling Limits
- MTA updates every 30 seconds server-side
- Polling faster than 30 seconds is pointless
- Frontend polls /api/board every 15 seconds (not MTA directly)

### No GPS Feed
- Metro North does NOT publish VehiclePosition (GPS) data
- Only TripUpdates and Alerts feeds are available

### Shore Line East
- Discontinued west of New Haven in Spring 2024
- No CTrail or Shore Line East feed needed for this project

---

## 🏗️ Architecture — How Everything Connects

```
MTA Server
  └─ updates every 30 sec
       │
       ▼
Laravel Backend (Hostinger)
  ├─ MetroNorthService.php
  │    ├─ fetches binary protobuf from MTA
  │    ├─ decodes using lowa/gtfs-realtime-php
  │    ├─ filters Stratford stop only
  │    ├─ splits by direction (New Haven vs NYC)
  │    └─ caches result for 20 sec
  └─ API routes
       ├─ GET /api/board  → clean JSON response
       └─ GET /api/alerts → alerts JSON
            │
            ▼
React Frontend
  ├─ useTrainData hook polls /api/board every 15 sec
  ├─ /board page → fullscreen Vesta flip board (TV)
  └─ /embed page → lightweight iframe version (Squarespace)
       │                    │
       ▼                    ▼
  TV Screen            Squarespace Website
  (Raspberry Pi)       (Business Plan iframe)
```

---

## 🛠️ Tech Stack

| Layer          | Technology                        |
|----------------|-----------------------------------|
| Frontend       | React JS                          |
| Backend        | Laravel PHP                       |
| Protobuf       | lowa/gtfs-realtime-php (Composer) |
| Caching        | Laravel file cache or Redis       |
| Hosting        | Hostinger Shared Hosting          |
| Display device | Raspberry Pi (client manages)     |
| Website embed  | HTML iframe on Squarespace        |

---

## 📦 Final Deliverables

1. Live URL → https://[clientdomain].com/board (TV display)
2. iframe embed code for Squarespace
3. Full source code (discuss with client)
4. One-page Raspberry Pi setup instructions for client

---

## ⏳ Still Pending From Client

- [ ] Restaurant logo (PNG, 500x500px or higher)
- [ ] Brand colors or preferred color scheme
- [ ] Visual references or layout inspiration

Build in your own Vesta Board style first. Apply client branding once assets arrive.

---

## 🚦 Build Order (Follow This Sequence)

1. Find Stratford stop_id from GTFS zip — do this before any filter code
2. Build and test /api/board returning real MTA data
3. Build /api/alerts endpoint
4. Build React Board page with Vesta flip design (use dummy data first)
5. Connect real API to React frontend
6. Make fully responsive across all breakpoints
7. Build /embed page as lighter version of Board
8. Deploy to Hostinger
9. Test fullscreen on Raspberry Pi
10. Hand embed code to client for Squarespace
11. Apply client logo and branding once received

---

## 💡 Rules Claude Must Always Follow in This Project

- Always check this file before making any architectural decision
- Never add features outside the defined scope
- Never create extra pages or routes
- Never call MTA APIs from the React frontend
- Always cache MTA responses — never make live MTA calls per user request
- Always use lowa/gtfs-realtime-php for protobuf decoding
- Keep the flip animation — it is a core requirement, not optional
- Every screen size must look impressive — check all breakpoints
- When in doubt about scope, refer back to this file
