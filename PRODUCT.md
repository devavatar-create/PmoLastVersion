# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Three groups use the system about equally, each every day in their own role:

- **Production departments** (engineering/drawing, purchasing, production, QC, delivery, accounting): accept the work dispatched to them, tick checklists, and press "เสร็จงาน" — mostly from the Planning page (`/planning/board`), often on a phone in the factory.
- **Project managers (PM) and admins**: create projects and cabinets from templates, dispatch sub tasks to departments with due dates, chase work that is waiting, and keep dates and status right.
- **Executives**: read the overview dashboard and the printable CEO report to see which projects are late or at risk and which department holds the late work.

## Product Purpose

Avatar Electric PMO tracks projects and the production of electrical cabinets (`Project → Cabinet → Task → Sub Task → Checklist`). It replaces tracking in Excel and LINE chats.

It succeeds when:
1. nobody has to phone or message to ask where a job stands, because the system already shows it;
2. more cabinets ship on time, because bottlenecks and late work show up before the due date, naming the department that holds them.

## Positioning

It is built around how Avatar Electric actually builds cabinets: every cabinet comes from a cabinet template, and progress comes from checklist ticks that roll up through the levels. Departments accept, start and finish their own dispatched work, so status comes from the people doing the work and not from a PM updating a spreadsheet.

## Operating Context

- **Places and devices:**
  - Factory floor on a phone. Users may be wearing gloves or standing in strong light.
  - Office desktops. Desktop is the main way the system is used.
  - Projected on a large screen in meetings.
  - The CEO report is printed on A4 company letterhead.
- **Planning page:** the main daily working page for departments.
- **Project and cabinet pages:** where PMs do detailed editing.
- **Dashboard and CEO report:** for review meetings.
- **Updates and comments:** discussion and photos go in comments (chat), which is the only place files can be uploaded (since 2026-10-20). Notifications are in-app and web push. Reminders run every day.

## Capabilities and Constraints

- **Language and dates:** All UI text and activity-log messages are Thai. Dates use the Buddhist calendar, dd/mm/yyyy (e.g. 20/10/2569).
- **Roles:** admin, project_manager, sales, production, member, plus per-department permissions (Settings → สิทธิ์การใช้งาน).
  - PMO roles see every department.
  - Everyone else sees only the projects their department works on, enforced on the server.
- **Workflow:**
  - Sub task assignment moves UNASSIGNED → ASSIGNED → ACCEPTED → IN_PROGRESS → COMPLETED.
  - Only admin and PM may dispatch.
  - A completed or cancelled project is read-only.
- **Progress:**
  - Calculated from checklists and averaged upward through the levels.
  - Task progress and production progress are always shown separately.
- **Production hosting:**
  - Shared hosting with no shell, npm or artisan.
  - Schema changes go through phpMyAdmin as SQL generated from migrations.
  - Front-end assets are built locally and uploaded.
- **Planning page:** a generated single-file app (`resources/planning/index.html` → `planning:build`). It is never edited by hand after generation.
- **Decided (2026-10):**
  - "Done" is one rule everywhere: the checklist is complete OR the department pressed "เสร็จงาน" (`CabinetSubtask::isDone()`).
  - A sub task is late from the day after its due date (`due_date < today`).
  - The typeface is **Anuphan** in every screen, the Planning page and the printed reports (loaded from Bunny Fonts; `tailwind.config.js` `fontFamily.sans`).

## Brand Commitments

- Company name: Avatar Electric. Logo: `public/images/logo.png`; app icons are in `public/images/`.
- The CEO report must use the company letterhead (from "Form-แบบที่13 ภายใน"): `public/images/report/letterhead-header.png` and `letterhead-footer.png`.

## Evidence on Hand

- Real project data in the working database, e.g. SO26090149 "เคเบิ้ลใต้ดิน ถนนวิทยุ (Modify)" with 18 cabinets.
- Demo seed: `DemoProjectSeeder` (PJ-690001).
- No customer testimonials, metrics on time saved, or benchmark figures exist. Do not invent them.

## Product Principles

1. **Status without asking.** Every screen should answer "where is this job and who has it" at a glance, so nobody has to call or message to find out.
2. **Show trouble before the due date.** Put late and at-risk work first, and name the department holding it.
3. **One number everywhere.** A count (progress, late, done) means the same thing on every page and in every report.
4. **Works for every age, on every screen.** Readable and easy to tap for older users and gloved hands on a phone. Clear on a meeting-room projector and on printed A4.
5. **The system enforces the rules.** Every change goes through the same server-side workflow checks, whatever page or automation makes it.

## Accessibility & Inclusion

- Users of many ages and computer skill levels: Thai text must be comfortable to read (no tiny secondary text for important facts), with plain Thai wording.
- Phone use in the factory: large touch targets usable with gloves, and contrast that holds up in bright light.
- Projector and print: key numbers and status must stay legible from across a room and in A4 print (`print-color-adjust: exact` on the report).
- Status must never depend on colour alone. Pair colour with a word or an icon (e.g. "ล่าช้า", "เลยกำหนด 3 วัน").
