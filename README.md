# Vacation Brain


## v1.15.1 — Shared account / Travel Match primary photo
- Travel Match onboarding now uses a real image upload instead of a photo URL field.
- The primary Travel Match photo is the same image as the user's Account Settings profile picture.
- Changing the profile picture from either Account Settings or Travel Match Profile synchronizes both surfaces.
- Photos 2–4 remain Travel Match-only gallery images.
- Code-only deploy: no new SQL migration is required.

## v1.15 — Admin catalogs, Shop, Destinations, Brand & PWA

- Admin Dashboard now lives in the user settings menu directly above Log out.
- User sidebar adds Destinations and Shop with tighter navigation spacing.
- Admin adds Users, Destinations, Merch / Shopping, Brand & PWA, AI / API Keys, and System Upgrade navigation.
- Destinations and Shop are database-managed catalogs.
- Shop includes product listing, product detail, session cart, and checkout shell.
- Brand & PWA lets Admin upload a site logo, app icon, and splash image and configure installable-app metadata.
- Migration `022_admin_brand_shop_destinations.sql` upgrades existing installs to v1.15.

# Vacation Brain — Single-Install Build v1.12

## v1.14 — One-click database upgrades

Vacation Brain now includes a VP3-style **`upgrade.php`** workflow for all future SQL changes. Admin can open **Admin → System Upgrade**, review the installed and available app versions, see the exact pending numbered SQL migrations, and click **Run Vacation Brain Upgrade** once.

The upgrader now:

- keeps a permanent `schema_migrations` ledger of every successfully applied numbered SQL file;
- runs only pending migrations and always runs them in numeric order;
- records SHA-256 checksums, statement counts, runtime, version-after, and applied time;
- uses a database advisory lock so two upgrades cannot run at the same time;
- logs successful and failed upgrade attempts in `upgrade_runs`;
- detects if an already-applied SQL migration file was later changed;
- automatically baselines existing pre-v1.14 installations so old SQL is not replayed;
- uses the same migration ledger during a brand-new installation, so fresh installs and future upgrades follow one system.

Future Vacation Brain database changes should be added only as the next numbered migration (`022_...sql`, `023_...sql`, etc.). The migration should be retry-safe because MySQL DDL can auto-commit before a later statement fails. The assistant will maintain those migration files as the application evolves; deployment remains: upload the new code, visit `/upgrade.php`, and click the upgrade button.

## v1.13 — Admin AI / API providers

- New **Admin → AI / API Keys** page.
- Server-side encrypted credentials for **OpenAI**, **Claude / Anthropic**, and **ElevenLabs**.
- Enable/disable each provider and choose the default Vacation Brain LLM/voice provider.
- Editable default model plus ElevenLabs voice ID.
- **Save & Test** validates each provider against a non-generation account/model endpoint.
- Provider test history/status is stored for Admin troubleshooting.
- The installer automatically creates the internal encryption material; there is no additional setup field or secret to enter.
- Saved API keys are never rendered back to the browser.
- The existing Vacation Brain agent automatically uses the enabled default OpenAI/Claude provider and falls back to the local rules engine if the provider is unavailable.
- Basic provider request/token usage is recorded for Admin visibility.

This package is the current one-install Vacation Brain application. Upload/extract once, run `install.php`, and the installer imports the full schema **plus the 2,200-record launch content library** and creates the first administrator.




## v1.12 — Logged-in shell + real photo uploads

- Logged-in application navigation moved into a persistent left sidebar.
- Bottom user avatar opens a dropdown for profile, Account & Settings, Travel Match Profile, notification settings, Admin, and logout.
- Dedicated Account & Settings page with profile-picture upload, display name, username, email, timezone, country, sarcasm/privacy settings, and password change.
- Travel Matching now uses real JPEG/PNG/WebP uploads for up to four profile photos; the first available image is the primary discovery image.
- Uploads use random server-generated file names and are limited to validated image MIME types, 8 MB, and safe image dimensions.
- Existing Admin tools are surfaced as a dedicated Admin sidebar workspace.

## v1.11 — Travel Match quality + onboarding

Travel Matching now adds the quality layer around the existing discovery/match/chat loop:

- dedicated 4-step **Travel Match onboarding** before first enrollment
- profile readiness score with playful missing-item guidance
- minimum profile quality required before a profile can enter discovery
- smarter discovery ranking using readiness, recency, travel pace, budget style, lightweight trait overlap, destination/location signals, and prior pass feedback
- a stable daily curated set of up to **8 Travel Match picks** instead of reshuffling the top profiles on every refresh
- optional pass feedback: too far, different budget, different pace, not enough in common, or just not interested
- ordinary **Unmatch** separate from Block/Report; it closes the match/chat while preserving internal history and creates no safety report
- notification preference controls for matches, messages, reactions, daily questions, discoveries, streaks, daily check-ins, and achievement/merch unlocks
- discovery cards can show lightweight curation reasons such as similar vacation pace or active today, while the actual compatibility percentage remains hidden until Extended View

## v1.10 — Post-match social loop

Travel Matching now includes the full post-match conversation loop:

- react to a matched user’s relevant Vacation Brain response with **Same**, **Explain yourself**, **Red flag**, or **We’re going to argue**; the reaction is written into the private conversation as a contextual opener
- reply to a specific chat message
- react to chat messages with **Same**, laugh, heart, and red-flag reactions
- in-app notifications for new mutual matches, new messages, message/profile reactions, Match Discoveries, and Travel Match achievements
- notification unread badge + notification center
- upgraded **Matches & Messages** inbox with unread counts, search, pin/unpin, archive/restore, and message previews
- inbox rows use the stored match compatibility score instead of recomputing full compatibility for every contact

## What is built

### Public diagnosis funnel
- Minimal diagnosis-first landing page
- **Take the Vacation Brain Self-Diagnosis Quiz** primary CTA
- Qualified Professional Vacation Brain Assessment secondary path
- 10 stored Tinder-style diagnosis cards
- Hidden preference/trait effects per answer
- 0–100 diagnosis severity
- Starting Vacation Brain Score
- Funny diagnosis, summary, and profile-aware vacation “prescription”
- Account creation after results

### Logged-in Today
- Vacation Brain Score
- Daily check-in
- Current/lifetime streak tracking
- Random seeded Vacation Brain agent observations
- Top learned traits
- Achievements
- Fast paths into Swipe, Escape tools, Dream Trips, and Travel Matching

### Continuing Vacation Swipe
The diagnosis is only the beginning. `/swipe.php` uses the large launch deck to keep learning the user.

Installed launch feed:
- 500 swipe scenarios
- 200 would-you-rathers
- 150 this-or-that cards
- 200 destination prompts
- **1,050 profile-learning cards total**

Every feed card has two choices. Choices update hidden traits and add Vacation Brain Score points.

### Vacation Breaks
- 50 installed micro-escape records
- 1–5 minute break experiences
- Activity/instruction copy
- Completion event tracking
- Score awards
- Behavior-based achievements

Routes:
```text
/breaks.php
/vacation-break.php?id=...
```

### Vacation Excuse + Out-of-Office generators
`/generator.php` provides:
- 100 Vacation Excuses
- 100 Out-of-Office messages
- copy-to-clipboard
- repeat generation
- user event tracking
- score/achievement support

Examples are content records, not hard-coded page copy, so future AI batches immediately expand the generators.

### Vacation Substitutions
- Six fully structured launch itineraries
- Step-by-step local pretend-vacation plans
- Completion scoring and event tracking
- City input
- Tagged local-place matching by itinerary step
- Admin local-place manager
- 75 additional substitution ideas in the content library for future itinerary expansion

Launch itineraries:
- Fake Mexico Day
- Resort Without the Flight
- Italian Afternoon
- Beach Brain Day
- Spa Reset Day
- Cruise Day, No Ship

### Achievements + behavior-triggered merch
Achievement evaluation now supports:
- Vacation Brain Score thresholds
- trait thresholds
- event-count rules
- current check-in streaks
- lifetime check-ins

Launch behavior achievements include:
- Seven-Day Daydream
- Thirty Days Mentally Away
- Vacation Break Regular
- Professional Escapist
- Excuse Department
- Out-of-Office Expert
- Local Escape Artist
- Vacation Swipe Scholar
- Vacation Swipe Professor
- Basically at the Airport

Existing profile achievements such as **Mentally Checked Out**, **No Activities Before 10AM**, **Pool Person**, and **Professional Daydreamer** remain in place.

`/merch.php` turns unlocked achievements and strong traits into merch concepts. The current build includes shirt/hoodie/mug/sticker product records and initial achievement-triggered designs such as:
- MENTALLY CHECKED OUT
- NO ACTIVITIES BEFORE 10AM
- POOL PERSON
- PROFESSIONAL DAYDREAMER
- 7 DAYS MENTALLY AWAY
- 30 DAYS MENTALLY AWAY
- VACATION EXCUSE DEPARTMENT
- I TAKE VACATIONS IN 3-MINUTE INCREMENTS
- NO FLIGHT. STILL ON VACATION.

Product rendering/fulfillment is intentionally still a later commerce integration; the unlock/recommendation architecture is working now.

### Qualified professional assessments
- Public request intake
- links the latest user diagnosis when available
- Admin/assessor review queue
- assessor notes and structured result storage
- internal `assessor` role
- Admin user-role manager

A Vacation Brain Qualified Assessor is an internal Vacation Brain travel-preference role only. It is **not** a medical, psychological, therapeutic, or healthcare credential.


## v1.3 fun-first experience layer

The main logged-in navigation is now intentionally consumer-simple:

```text
Today · Swipe · Escape · Dream · Me
```

### Today v2
- score-level progression toward the next Vacation Brain classification
- daily check-in and streak
- current Vacation Brain archetype
- profile-derived daily roast/finding
- strongest learned signals
- recent achievements
- fast paths into Weather Envy, Vacation Breaks, Dream Trips, and merch

### Vacation Brain archetypes
The app now derives a live personality/archetype from actual trait data, including patterns such as Professional Lounger, Upgraded Escapist, Chaotic Explorer, Food-First Traveler, Spreadsheet Vacationer, Deal-Chasing Dreamer, Beach Brain, Night Owl Nomad, and Easy Escape Artist. Archetypes evolve as the user keeps swiping.

### Escape hub
`/escape.php` brings the playful tools into one place:
- Vacation Substitutions
- Weather Envy
- Vacation Breaks
- Vacation Excuse Generator
- Out-of-Office Generator
- Roast My Vacation Brain

Weather Envy uses Open-Meteo and requires no API key.

### Dream Trips
`/dream.php` and `/dream-trip.php` provide a deliberately lightweight pre-planning/daydreaming experience. Users can create fantasy trips, set a dream intensity, save hotels/food/activities/ideas, repeatedly reopen the trip, and watch its private internal intent score grow. The user-facing language stays playful (Pure daydream → Getting suspicious → Looking pretty real → Book-it energy → Basically packed) rather than turning the app into a booking dashboard.

### Profile-aware Vacation Brain Agent
A persistent ChatGPT-style composer is available throughout logged-in pages. `/agent.php` stores a conversation and can answer from the user's Vacation Brain Score, traits, archetype, Dream Trips, seeded jokes, excuses, and OOO content. This build requires no external model/API key; the agent service is structured so an LLM provider can be connected later without changing the core UX.

### Sharing + richer behavior merch
- shareable Vacation Brain profile/result card
- native share when supported, clipboard fallback
- merch ideas can now come from live behaviors such as check-in streaks, Weather Envy use, Dream Trip revisits, roasts, Vacation Breaks, and swipe volume—not only predefined achievements

## Installed 2,200-record launch content library

The installer now loads the full seed target rather than an empty manifest:

| Type | Installed |
|---|---:|
| swipe_question | 500 |
| would_you_rather | 200 |
| this_or_that | 150 |
| destination_prompt | 200 |
| vacation_excuse | 100 |
| out_of_office | 100 |
| agent_comment | 250 |
| achievement_copy | 100 |
| merch_slogan | 200 |
| vacation_break | 50 |
| substitution_prompt | 75 |
| challenge | 100 |
| prop_bet | 100 |
| personality_result | 75 |
| **Total** | **2,200** |

The launch library is a structured template-generated seed set and has passed structural QA for counts, unique slugs, required choice relationships, trait references, and SQL parsing. It has **not** been individually human-copyedited line-by-line. Admin can search, retire, draft, or replace records through the Content Library, and the AI review workflow remains available for future editorial batches.

## AI Content Factory
No AI-provider key is required for installation.

Admin can:
1. Create a structured generation job.
2. Copy the generated prompt into ChatGPT or another model.
3. Paste the returned JSON batch into Vacation Brain.
4. Review/edit/reject/approve candidates.
5. Publish accepted candidates into the master content library.

Publishing supports:
- master content records
- tags
- swipe choices
- hidden trait effects
- Vacation Substitution templates/steps
- Vacation Break detail records
- exact duplicate fingerprints for AI-published content

The included `seed-generation-output-schema.json` defines the structured batch format. `samples/ai-content-batch-example.json` is a paste-ready example.

## Admin areas

```text
/admin/index.php
/admin/content.php
/admin/ai-content.php
/admin/review-content.php
/admin/places.php
/admin/assessments.php
/admin/users.php
```

### Content Library
The new Admin Content Library can:
- search title/body/slug
- filter by content type
- filter by status
- browse the 2,200+ installed records
- publish records
- return records to draft
- retire weak/repetitive records

## Requirements
- PHP 8.1+
- MySQL 8.0+
- PDO MySQL extension
- web server capable of PHP
- HTTPS recommended in production

No Composer or framework installation is required.

# One-install setup

1. Upload/extract this package into the desired web folder.
2. Make sure PHP can write `config.php` during installation.
3. Open:

```text
https://your-site.example/install.php
```

4. Enter:
   - database host / port
   - database name
   - database username / password
   - application base URL
   - timezone
   - first administrator name / email / password
5. Click **Install Vacation Brain**.

The installer automatically:
- creates the database when permitted, otherwise uses the existing database;
- imports every numbered SQL migration in order;
- imports the entire 2,200-record seed library;
- creates the first admin account;
- writes `config.php`;
- locks itself once `config.php` exists.

The installer executes the larger seed SQL **statement-by-statement**, avoiding a single giant multi-megabyte SQL request.

There are **no extra application security keys and no AI API keys to configure**.

## Manual SQL fallback

Import:

```text
db/vacation-brain-full-install.sql
```

The manual SQL uses the default database name `vacation_brain`. The web installer is recommended because it supports a custom database name and creates the first administrator automatically.

## Product boundary
Vacation Brain deliberately uses “diagnosis” language as a brand gag. Public assessment surfaces state that Vacation Brain diagnoses are entertainment and travel-preference guidance only and are not medical or mental-health diagnoses.

## Still intentionally later
The schema already supports much of this, but these are not pretending to be complete yet:
- travel package marketplace / booking integrations
- provider subscription and billing UI
- final merch rendering/ordering/fulfillment
- external local-business/provider feeds
- direct in-app model API integration

## v1.4 — Travel Matching / Compare Vacation Brains

Vacation Brain now includes an optional **18+ Travel Matching** mode. It is disabled by default and does not expose ordinary Vacation Brain users automatically.

Implemented:

- Dating / travel-buddy / either matching modes.
- Travel Match profile with age, display gender, preferred audience, location, travel pace, budget style, bio, favorite destination, and explicit travel dealbreakers.
- Compatibility score calculated from existing Vacation Brain traits and confidence values.
- Weighted agreement/conflict reporting around budget, morning tolerance, planning, spontaneity, relaxation/adventure, nightlife, convenience, flights, food, beach/pool/resort and other learned traits.
- Like / pass actions and mutual Travel Matches.
- Compare Vacation Brains share result.
- Invite link for someone who is not yet in Travel Matching. New users still complete the Vacation Brain self-diagnosis first.
- Three private two-person compatibility games: **Would We Survive the Airport?**, **Pick Our First Trip**, and **Vacation Red Flags**.
- Game answers remain hidden until both participants have answered.
- Matching achievements and score events.

Group travel matching is intentionally **not** included yet. This phase establishes the one-to-one social model first.



## v1.6 — Travel Match engagement loop

Mutual matches now have a dedicated **Match Space** with:
- 3–5 viewer-specific Vacation Brain icebreakers
- one shared ridiculous Daily Match Question with answers hidden until both respond
- shared activity streaks driven by messages, daily answers, games, and shared-dream activity
- automatic Match Discoveries for meaningful agreements/conflicts
- match-owned achievements such as Same Brain, First Argument, Airport Compatible, No Sunrise People, and Dreaming Together
- five mini-games: Would We Survive the Airport?, Pick Our First Trip, Budget Battle, Hotel Room Test, and Vacation Red Flags
- one lightweight Shared Dream Trip with destination, vibe, budget energy, dream level, notes, and shared itinerary ideas

Travel Matching profiles now explicitly store **My gender** and **Preferred partner gender**. Those values are user-entered discovery filters and are not inferred from Vacation Brain behavior or used in the compatibility percentage.

Travel Matching is also explicitly enrollable/unenrollable. Unenrolling immediately removes the profile from new discovery while preserving the user account, prior Vacation Brain data, existing mutual matches, and private message history.

## v1.5 — Travel Match contacts + messaging

Travel Matching now continues past the mutual like instead of ending at a compatibility card.

Implemented:

- Like / Pass controls directly on Travel Matching discovery cards.
- Mutual likes create one canonical `travel_matches` record.
- Tinder-style **It’s a Travel Match** popup when a new match is first seen by each person.
- New mutual matches automatically appear in **Matches & Messages**; there is no separate contact-approval step.
- Private 1:1 messaging is available only while the mutual match is active.
- Conversation list is ordered by recent activity and includes message previews and unread counts.
- Primary navigation shows an unread-message badge.
- Match chat polls for new messages and supports Enter-to-send / Shift+Enter for a newline.
- First-message achievement: **Said Hello**.
- Block closes the active match conversation.
- Individual incoming messages can be reported; a message report also blocks the match.
- Admin Travel Match Safety shows only the specific message attached to a message-level report, alongside profile-level reports.
- Message sending is rate-limited to reduce rapid spam.

Messaging is intentionally one-to-one for now. Group messaging and group-trip matching remain later work.

### Travel Matching extended-profile reveal

Travel Matching discovery cards intentionally do **not** show a compatibility percentage. Swipe discovery stays lightweight: photo, first name/age, Vacation Brain archetype, pace, favorite destination, bio/teaser, and Like / Pass.

The compatibility calculation and detailed profile intelligence are revealed only after **View Profile** is opened. The extended profile includes:

- Travel Match percentage and compatibility commentary.
- Strongest agreements and likely vacation conflicts.
- Vacation Brain questionnaire responses selected specifically for the person viewing the profile.
- Exact same-question overlap is prioritized: when both people answered the same question, the profile shows both answers and identifies whether they agreed or differed.
- When there is no direct question overlap, answers are ranked by relevance to the viewer’s strongest shared/conflicting traits, then by humor/sarcasm value.
- Response diversity limits prevent one trait from flooding the profile.

This keeps the swipe feed fast while making the extended profile substantially more personal and entertaining.



## v1.8 — Swipe gallery + full-page Extended Profile

- Discovery cards show exactly one profile image at a time.
- If a profile has multiple images, users can move backward/forward through them directly on the swipe card before opening profile details.
- Photo position is shown with a compact counter/dots; there is no thumbnail gallery in discovery or Extended View.
- Compatibility remains hidden on the swipe card.
- `View Profile · See compatibility` now opens Extended View in a full-screen modal overlay instead of navigating away from Travel Matching.
- Extended View focuses on profile details, compatibility, relevant Vacation Brain responses, games, and messaging; it shows the primary image rather than a second full gallery.

## v1.7 — Travel Matching discovery/profile polish

Travel Matching now behaves more like a deliberate dating/discovery product while keeping Vacation Brain compatibility hidden until the user asks to see it.

Implemented:
- discovery cards do **not** calculate or display Travel Match percentage;
- compact cards load only the primary photo, basic travel cues, Vacation Brain archetype, and at most one prompt teaser;
- **View Profile · See compatibility** is the reveal step for compatibility score, agreements/conflicts, relevant questionnaire history, the full photo gallery, and profile prompts;
- up to four profile photos, with only the primary image used in discovery;
- up to three optional Vacation Brain profile prompts selected from a travel-specific prompt catalog;
- age-range discovery preferences;
- location scope filters: anywhere, same country, same state/region, or same city;
- optional pace and recently-active filters in the discovery feed;
- privacy-controlled broad activity cues such as **Active today** or **Active this week**; exact last-seen timestamps are never exposed;
- gender and preferred-partner-gender remain user-entered discovery filters and remain excluded from compatibility scoring.

This phase deliberately leaves group matching out and keeps richer profile data lazy-loaded behind the extended-profile click.


## v1.9 — Travel Matching entry preload

Travel Matching now uses a branded entry transition when the user enters the Match section. The transition begins on the originating page before navigation, remains visible while the server renders discovery, and then waits only for the first visible profile images to decode before fading. A short minimum display prevents a distracting flash; a bounded image wait prevents the loader from becoming a blocker on slow media.

Inside Travel Matching, photo browsing and Extended Profile interactions remain lightweight and do not intentionally show the large entry animation. Compatibility remains lazy and is still revealed only when the user explicitly opens Extended View.


## v1.16
- Sample Data toggle with 10 Travel Match profiles, 10 destinations/reports, and expanded merch.
- Destination Research reports use the configured OpenAI/Claude provider and save normalized results/sources for reuse.
- Sticky ChatGPT-style Vacation Brain agent composer.
- One-time Admin Dashboard owner recovery for legacy installs.
