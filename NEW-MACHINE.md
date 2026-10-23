# ติดตั้งบนเครื่องใหม่ (สำหรับพัฒนาต่อ)

## โปรแกรมที่ต้องมี

- PHP 8.2 ขึ้นไป + Composer
- MySQL / MariaDB (เช่น XAMPP)
- Node.js 20 ขึ้นไป + npm
- Git

## ขั้นตอน

```bash
git clone https://github.com/itservice-bd-git/PmoLastVersion.git
cd PmoLastVersion

# 1. สร้างฐานข้อมูลเปล่าชื่อ avatar_pmo_v1 ใน MySQL ก่อน (phpMyAdmin หรือคำสั่งด้านล่าง)
mysql -u root -e "CREATE DATABASE avatar_pmo_v1 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"

# 2. ติดตั้งทุกอย่าง: vendor/, .env (คัดจาก .env.example), APP_KEY, ตาราง, node_modules, build
composer setup

# 3. ใส่ข้อมูลตัวอย่าง (โปรเจกต์ PJ-690001 พร้อม 4 ตู้)
php artisan db:seed

# 4. เปิดใช้งาน (server + queue + log + vite พร้อมกัน)
composer dev
```

เปิด http://localhost:8000

## สิ่งที่ไม่ได้อยู่ใน Git (ตั้งใจ)

| สิ่งนี้ | ทำไมไม่อยู่ | บนเครื่องใหม่ทำอย่างไร |
|---|---|---|
| `.env` | มีรหัสผ่านและคีย์ลับ และ repo นี้เป็น public | `composer setup` สร้างจาก `.env.example` ให้ ถ้า MySQL มีรหัสผ่าน ให้ใส่ `DB_PASSWORD` เอง |
| `vendor/` | ไฟล์ของ Composer ที่สร้างใหม่ได้ | `composer install` (อยู่ใน `composer setup` แล้ว) |
| `node_modules/`, `public/build/` | สร้างใหม่ได้ | `npm install` / `npm run build` (อยู่ใน `composer setup` แล้ว) |
| ข้อมูลในฐานข้อมูลจริง | ข้อมูลลูกค้า | ถ้าต้องใช้ ให้ export จาก phpMyAdmin แล้วส่งทาง USB / ไดรฟ์ภายใน ห้ามขึ้น GitHub |
| `storage/app/private/project-files/` | ไฟล์แนบของโปรเจกต์ (ข้อมูลลูกค้า) | ส่งพร้อมฐานข้อมูลถ้าต้องใช้ |

## ตั้งค่าเพิ่มเติม (ไม่บังคับ)

- Web Push: `php artisan webpush:keys --write` จะสร้างคีย์ใส่ `.env` ให้ (เว้นว่างไว้ = ปิดการแจ้งเตือนแบบ push)
- รันเทสต์: `php artisan test`

อ่าน `CLAUDE.md` สำหรับโครงสร้างระบบและข้อจำกัดของเซิร์ฟเวอร์จริง
