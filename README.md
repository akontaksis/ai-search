# AI Search — WordPress Plugin

AI-powered natural language search για WordPress sites. Ο επισκέπτης γράφει αυτό που ψάχνει σε απλή γλώσσα και το plugin τον οδηγεί στη σωστή σελίδα.

**Τρέχουσα έκδοση:** `1.1.0`

---

## Πώς λειτουργεί

```
Χρήστης γράφει ερώτημα
        ↓
Rate limit check (10 req/min ανά IP)
        ↓
Cache check (24h — ίδια ερώτηση επιστρέφει αμέσως)
        ↓
SQL pre-filter: LIKE στα keywords + title → top 15 σχετικές
        ↓
Claude Haiku: επιλέγει 1-3 σελίδες από τις 15
        ↓
Εμφάνιση αποτελεσμάτων ως cards με link
```

**Παράδειγμα:**
```
Χρήστης: "θέλω να βγάλω άδεια οικοδομής"
→ Card: Υπηρεσία Δόμησης
   Περιγραφή: Άδειες οικοδομής, ρυμοτομία, αυθαίρετα
   [Μεταβείτε →]
```

---

## Απαιτήσεις

| | Ελάχιστο |
|---|---|
| WordPress | 6.0+ |
| PHP | 8.0+ |
| MySQL | 5.7+ |
| Επέκταση PHP | `openssl` |
| API | [Anthropic API key](https://console.anthropic.com/) |

---

## Εγκατάσταση

1. Αντιγράψτε τον φάκελο `ai-search/` στο `wp-content/plugins/`
2. Ενεργοποιήστε το plugin από το **WordPress Admin → Plugins**
3. Μεταβείτε στο **Settings → AI Search** και ρυθμίστε API key + όνομα οργανισμού
4. Επιλέξτε ποιους **Post Types** θέλετε να ευρετηριάσετε
5. Πατήστε **Re-index τώρα** (Βήμα 1)
6. Πατήστε **AI Enhance** (Βήμα 2) για καλύτερα αποτελέσματα
7. Προσθέστε το shortcode `[ai_search]` σε οποιαδήποτε σελίδα

---

## Ρυθμίσεις (Settings → AI Search)

| Ρύθμιση | Περιγραφή | Default |
|---|---|---|
| **Anthropic API Key** | Κλειδί για το Claude API. Αποθηκεύεται κρυπτογραφημένο (AES-256). | — |
| **Όνομα οργανισμού** | Χρησιμοποιείται στο AI prompt για context. | Τίτλος site |
| **Post Types** | Pages, Posts, custom post types προς ευρετηρίαση. | Pages |
| **Placeholder κειμένου** | Το hint μέσα στο search box. | `Τι ψάχνετε;` |
| **Μήνυμα χωρίς αποτέλεσμα** | Εμφανίζεται όταν δεν βρεθεί τίποτα. | `Δεν βρέθηκε σχετική υπηρεσία.` |

---

## Shortcode

```
[ai_search]
```

Τοποθετήστε το σε οποιαδήποτε σελίδα, post ή widget.

---

## Ευρετηρίαση — 2 Βήματα

### Βήμα 1: Basic Re-index
Σκανάρει όλα τα επιλεγμένα post types και εξάγει βασικά keywords από το κείμενο. Δεν καλεί το Claude API.

- Εξαγωγή κειμένου από Elementor (`_elementor_data` meta)
- Περιγραφή: Excerpt → Yoast → πρώτες 30 λέξεις → τίτλος
- Keywords: εξαγωγή λέξεων, αφαίρεση stop words, top-20

### Βήμα 2: AI Enhancement ⭐
Ο Claude Haiku διαβάζει κάθε σελίδα μία-μία και γεμίζει:

| Πεδίο | Παράδειγμα |
|---|---|
| `description` | "Υπηρεσία για έκδοση αδειών οικοδομής και τακτοποίηση αυθαιρέτων" |
| `keywords` | "άδεια, οικοδομή, δόμηση, αυθαίρετο, ρυμοτομία, κτίριο" |
| `category` | "Τεχνικά" |
| `service_type` | "action" |

**Κατηγορίες:** Παιδεία, Οικονομικά, Τεχνικά, Κοινωνικά, Υγεία, Αθλητισμός, Πολιτισμός, Περιβάλλον, Διοίκηση, Άλλο

**Τύποι υπηρεσίας:** `info` (πληροφορία), `action` (αίτηση/διαδικασία), `contact` (επικοινωνία), `payment` (πληρωμή)

---

## Δομή Plugin

```
ai-search/
├── ai-search.php               # Main plugin file, AJAX handlers, activation
├── includes/
│   ├── class-indexer.php       # Basic + AI-enhanced indexing, Elementor support
│   ├── class-claude-api.php    # match() + enhance() — Anthropic API calls
│   ├── class-search.php        # SQL pre-filter + Claude matching + caching
│   ├── class-crypto.php        # AES-256 κρυπτογράφηση API key
│   └── class-admin.php         # Admin settings, re-index, AI enhance, table
├── assets/
│   ├── css/search.css          # Responsive frontend styles
│   └── js/search.js            # Vanilla JS, DOM-based, XSS-safe
├── templates/
│   └── search-form.php         # HTML shortcode template
├── uninstall.php               # Καθαρισμός κατά διαγραφή
└── README.md
```

---

## Database

### `wp_ai_search_index`

| Πεδίο | Τύπος | Περιγραφή |
|---|---|---|
| `id` | BIGINT PK | Auto increment |
| `post_id` | BIGINT | WordPress post ID (NULL για εξωτερικά links) |
| `title` | VARCHAR(255) | Τίτλος σελίδας |
| `url` | VARCHAR(500) | Πλήρες URL |
| `description` | TEXT | Περιγραφή (AI-enhanced ή fallback chain) |
| `keywords` | TEXT | Comma-separated λέξεις-κλειδιά |
| `category` | VARCHAR(100) | NULL = εκκρεμεί AI enhancement |
| `service_type` | VARCHAR(50) | `info / action / contact / payment` |
| `is_external` | TINYINT | 1 για εξωτερικά links |
| `priority` | INT | Βαρύτητα στην αναζήτηση (default: 5) |
| `last_indexed` | DATETIME | Τελευταία ευρετηρίαση |

### `wp_ai_search_log`

| Πεδίο | Τύπος | Περιγραφή |
|---|---|---|
| `id` | BIGINT PK | Auto increment |
| `query` | VARCHAR(500) | Το ερώτημα του χρήστη |
| `matched_index_id` | BIGINT | ID της πρώτης αντιστοίχισης |
| `cache_hit` | TINYINT | 1 αν απαντήθηκε από cache |
| `response_time_ms` | INT | Χρόνος απόκρισης σε ms |
| `created_at` | DATETIME | Timestamp |

---

## Ασφάλεια

| Μηχανισμός | Υλοποίηση |
|---|---|
| **CSRF protection** | `wp_nonce` σε όλα τα AJAX requests |
| **Capability check** | `manage_options` για admin actions |
| **Input sanitization** | `sanitize_text_field()` + `mb_strlen` check |
| **Input length limit** | Max 300 χαρακτήρες server-side |
| **Rate limiting** | 10 αναζητήσεις/λεπτό ανά IP (transients) |
| **API key encryption** | AES-256-CBC με key από `AUTH_KEY + AUTH_SALT` |
| **Output escaping** | `esc_html()`, `esc_url()`, `esc_attr()` παντού |
| **XSS prevention** | DOM `textContent` / `createElement` στο JS (όχι innerHTML) |
| **Direct access** | `ABSPATH` check σε κάθε αρχείο |
| **Uninstall** | `WP_UNINSTALL_PLUGIN` check |

---

## Κόστος API (εκτίμηση)

Χρησιμοποιεί **Claude Haiku** — το πιο οικονομικό μοντέλο της Anthropic.

| Σενάριο | Κόστος |
|---|---|
| Basic re-index (~200 σελίδες) | $0.00 (δεν καλεί API) |
| AI Enhancement (~200 σελίδες) | ~$0.10 (εφάπαξ) |
| 100 αναζητήσεις/ημέρα (90% cache hit) | ~$0.005/ημέρα |
| **~$1–2 / μήνα** για τυπικό οργανισμό | ✓ |

---

## Φάσεις Ανάπτυξης

### ✅ Φάση 1 — MVP
- Plugin scaffold + activation hooks
- Custom DB tables (`wp_ai_search_index`, `wp_ai_search_log`)
- Basic indexer (pages + configurable post types)
- Claude API integration για αναζήτηση
- Shortcode + responsive frontend (vanilla JS)
- Admin: API key (AES-256), site name, post types, re-index
- Caching 24h, rate limiting 10/min, security hardening

### ✅ Φάση 2 — Intelligence
- **SQL pre-filtering:** LIKE στα keywords + title πριν τον Claude
- **AI Enhancement:** Claude γράφει description, keywords, category, service_type
- **Elementor support:** εξαγωγή κειμένου από `_elementor_data` JSON meta
- **Admin progress bar:** live ενημέρωση κατά το AI enhancement
- Auto-invalidation cache μετά από enhancement

### Φάση 3 — Production-Ready
- External links (non-WP pages στον index)
- Auto re-index on `save_post`
- Error handling + logging βελτιώσεις

### Φάση 4 — Analytics & UX
- Dashboard: top queries, failed queries, response times
- Feedback buttons (👍👎)
- Top searches widget

---

## Τεχνολογίες

- **Backend:** PHP 8.0+, WordPress 6.0+
- **Frontend:** Vanilla JS (χωρίς jQuery), CSS3
- **AI Model:** `claude-haiku-4-5-20251001`
- **Storage:** Custom MySQL tables + WP transients
- **Namespace:** `AISearch` | **Prefix:** `ais_`

---

## Uninstall

Κατά τη **διαγραφή** (όχι απλά deactivation):
- Drops `wp_ai_search_index` και `wp_ai_search_log`
- Διαγράφει όλα τα options (`ais_*`)
- Καθαρίζει τα cached transients

---

## Changelog

### 1.1.0
- SQL pre-filtering πριν τον Claude (15 candidates αντί 20 random)
- AI Enhancement με progress bar στο admin
- Elementor content extraction από `_elementor_data` meta
- Configurable post types (pages, posts, custom)
- Pagination στον admin index table (50/σελίδα)
- Token reduction + query caching 24h

### 1.0.0
- Initial MVP release
