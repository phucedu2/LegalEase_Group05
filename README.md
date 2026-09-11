##⚖️ LegalEase - Legal Consultation Booking Platform

**FPT Aptech Computer Education**

**Tech Stack**: PHP 7.2+, MySQL 8.0, HTML5, CSS3, Bootstrap, JavaScript/jQuery

**Instructor**: Le Mong Thuy | **Batch**: T3.2603.E0 | **Group**: 5

---

## 👥 Team Members

- Tran Thien Phuc – FTS00075 - Student1708930 - Team Leader
- Tran Khanh Huy – FTS00088 - Student1665741 - Team Member
- Luong Hoang The Quan – FTS00089 - Student1686152 - Team Member
- Truong Kim Ngan – FTS00048 - Student1690424 - Team Member
- Nguyen Tan Tuyen – T1S2408012 - Student1604684 - Team Member

---

## 📖 Project Overview

LegalEase is a web-based platform that connects individuals and businesses with professional lawyers through a centralized system for searching, viewing profiles, and booking appointments. It solves the common problems of traditional lawyer-finding methods: lack of information transparency, difficulty comparing expertise, and time-consuming manual scheduling.

- Customers search for lawyers by legal specialization and city/province, view verified profiles with ratings, and book consultations based on real-time availability.
- Lawyers manage their professional profile, working schedule, and respond to appointment requests.
- Administrators verify lawyer registrations, oversee all appointments, and manage platform content (announcements, FAQs, notices).

---
## 📺 Video Demonstration: https://www.youtube.com/watch?v=Cnz0HsGskeU
---

## ✨ Key Features

### 🏠 Home Page (Guest)
- Search bar for lawyers by specialization and city/province
- Featured verified lawyer profiles with ratings
- Legal news / announcements section

### 👤 Customer Portal
- Register/login and manage profile
- Find & filter lawyers by location and specialization
- View detailed lawyer profiles and book appointments
- Manage payments and view billing history
- Cancel/track appointments, submit reviews after completed consultations
- Receive real-time notifications

### 👨‍⚖️ Lawyer Portal
- Register with specialization, experience, and location
- Submit ID/license documents for Admin verification
- Manage profile, achievements, and specialties
- Define availability time slots (Work Schedule)
- Confirm/reject appointment requests
- Dashboard with earnings overview and client analytics

### 🛠️ Admin Portal
- Dashboard with platform-wide statistics
- Manage & oversee all bookings (cancel/reschedule)
- Approve/reject lawyer verifications, disable/re-activate accounts
- Manage customer accounts
- Reporting & Analytics (revenue, appointments, top-performing lawyers)
- Content Management (FAQs, Guides/Notices, News, Announcements)

---

## 🗄️ Database Design

Relational database (`legalproject1`) with **18 tables** grouped into 6 functional areas:

| Group | Tables |
|---|---|
| Authorization & Accounts | `users`, `password_reset_tokens` |
| Profiles & Verification | `customer_profiles`, `lawyer_profiles`, `lawyer_verifications` |
| Location & Specialization | `locations`, `specializations`, `lawyer_specialties` |
| Appointment & Scheduling | `availability_slots`, `appointments` |
| Payment & Interaction | `payments`, `refunds`, `reviews`, `notifications`, `email_outbox` |
| Content & Administration | `content_management`, `site_content`, `system_audit_logs` |

Full ERD, DFD (Level 0/1/2), and detailed schema are available in the project's `System Analysis and Design Report`.

---

## 🚀 Getting Started

### Prerequisites
- PHP >= 7.2
- MySQL >= 8.0
- Web server (XAMPP / WAMP / Laragon)

### Installation

```bash
# 1. Clone the repository
git clone https://github.com/<your-username>/legalease.git
cd legalease

# 2. Import the database
mysql -u root -p legalproject1 < database/legalproject1.sql

# 3. Configure database connection
cp config.sample.php config.php
# Edit config.php with your DB host, username, password

# 4. Run with your local server (e.g. place in htdocs/ for XAMPP)
# then visit http://localhost/legalease
```

### Demo Accounts

| Role | Email | Password |
|---|---|---|
| Admin | admin@legalease.com | 123456 |
| Lawyer | lawyer@gmail.com | 123456 |
| Customer | tanbuyen@gmail.com | 123456 |

---

## 📁 Project Structure

```
legalease/
├── admin/              # Admin portal pages
├── lawyer/             # Lawyer portal pages
├── customer/           # Customer portal pages
├── includes/           # Shared PHP includes (db connection, functions)
├── assets/             # CSS, JS, images
├── database/           # SQL schema & seed data
└── config.php          # Database configuration
```

---

## 📄 License

This project was developed as an academic eProject for FPT Aptech Computer Education, Semester 1 - Course Project.
