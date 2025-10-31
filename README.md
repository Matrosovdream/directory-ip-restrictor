# 🧱 Directory IP Restrictor (WordPress Plugin)

**Directory IP Restrictor** is a lightweight WordPress plugin that lets you restrict access to specific folders or pages based on user roles and IP addresses.

---

## 🚀 Features

- Restrict access to **any folder or page path**
- Option to include **child paths**
- Flexible **role-based** and **IP-based** access control
- Simple, self-contained **admin settings page**
- “Active” toggle per rule
- Multi-group support (“Allow extra groups”)
- Works with both **IPv4** and **IPv6**

---

## 🛠️ Installation

1. Download the plugin ZIP or clone this repository:
   ```bash
   git clone https://github.com/Matrosovdream/directory-ip-restrictor.git
   ```
2. Copy the folder `directory-ip-restrictor/` into your WordPress `wp-content/plugins/` directory.
3. Activate it from **WordPress Admin → Plugins → Directory IP Restrictor**.
4. Go to **Settings → Directory IP Restrictor** and create your rules.

---

## ⚙️ Configuration

Each rule includes:
- **Folder or page:** Example: `/private` or `/wp-content/uploads/secure`
- **Restrict children:** Apply rule to all sub-paths
- **Active:** Enable/disable rule
- **Allowed user groups:** Select WordPress roles that can access
- **Allow extra groups:** Custom user/IP lists (newline-separated IPs)

Access is granted if:
1. The user has any selected role **OR**
2. The visitor’s IP matches one of the allowed IPs

---

## 🧩 Technical Notes

- Rules are stored in the WordPress options table (`dir_ipr_settings`).
- Uses early `template_redirect` hook to block or allow.
- For protecting static assets (e.g., `/uploads/formidable`), optional `.htaccess` or Nginx rewrites can route requests through WordPress.

---

## 📄 License

Released under the [MIT License](LICENSE).

---

## 🧑‍💻 Author

Developed by **Stanislav Matrosov**  
GitHub: [@Matrosovdream](https://github.com/Matrosovdream/)
