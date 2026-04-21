# AI Search — WordPress Plugin

AI-powered natural language search για WordPress sites. Ο επισκέπτης γράφει αυτό που ψάχνει σε απλή γλώσσα και το plugin τον οδηγεί στη σωστή σελίδα.

---

## Πώς λειτουργεί

```
Χρήστης γράφει ερώτημα
        ↓
Rate limit check (10 req/min ανά IP)
        ↓
Φόρτωση top-50 εγγραφών από index
        ↓
Claude API (επιλέγει 1-3 σχετικές σελίδες)
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

1. Κατεβάστε ή κλωνοποιήστε τον φάκελο `ai-search/`
2. Αντιγράψτε τον στο `wp-content/plugins/`
3. Ενεργοποιήστε το plugin από το **WordPress Admin → Plugins**
4. Μεταβείτε στο **Settings → AI Search** και ρυθμίστε:
   - Anthropic API key
   - Όνομα οργανισμού
5. Πατήστε **Re-index τώρα**
6. Προσθέστε το shortcode `[ai_search]` σε οποιαδήποτε σελίδα

---

## Ρυθμίσεις (Settings → AI Search)

| Ρύθμιση | Περιγραφή | Default |
|---|---|---|
| **Anthropic API Key** | Κλειδί για το Claude API. Αποθηκεύεται κρυπτογραφημένο (AES-256). | — |
| **Όνομα οργανισμού** | Χρησιμοποιείται στο AI prompt για context. | Τίτλος site |
| **Placeholder κειμένου** | Το hint μέσα στο search box. | `Τι ψάχνετε;` |
| **Μήνυμα χωρίς αποτέλεσμα** | Εμφανίζεται όταν δεν βρεθεί τίποτα. | `Δεν βρέθηκε σχετική υπηρεσία.` |

---

## Shortcode

```
[ai_search]
```

Τοποθετήστε το σε οποιαδήποτε σελίδα, post ή widget.

---

## Δομή Plugin

```
ai-search/
├── ai-search.php               # Main plugin file, AJAX handlers, activation
├── includes/
│   ├── class-indexer.php       # Σκανάρει WP pages, χτίζει το index
│   ├── class-claude-api.php    # Κλήση Anthropic API
│   ├── class-search.php        # Λογική αναζήτησης
│   ├── class-crypto.php        # AES-256 κρυπτογράφηση API key
│   └── class-admin.php         # Admin settings page
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

Δημιουργούνται 2 custom πίνακες κατά την ενεργοποίηση:

### `wp_ai_search_index`
Αποθηκεύει τις ευρετηριασμένες σελίδες.

| Πεδίο | Τύπος | Περιγραφή |
|---|---|---|
| `id` | BIGINT PK | Auto increment |
| `post_id` | BIGINT | WordPress post ID (NULL για εξωτερικά links) |
| `title` | VARCHAR(255) | Τίτλος σελίδας |
| `url` | VARCHAR(500) | Πλήρες URL |
| `description` | TEXT | Περιγραφή (excerpt / Yoast / πρώτες 30 λέξεις) |
| `keywords` | TEXT | Comma-separated λέξεις-κλειδιά |
| `category` | VARCHAR(100) | Κατηγορία |
| `service_type` | VARCHAR(50) | `info / action / contact / payment` |
| `is_external` | TINYINT | 1 για εξωτερικά links |
| `priority` | INT | Βαρύτητα (default: 5) |
| `last_indexed` | DATETIME | Τελευταία ευρετηρίαση |

### `wp_ai_search_log`
Αποθηκεύει κάθε αναζήτηση για analytics.

| Πεδίο | Τύπος | Περιγραφή |
|---|---|---|
| `id` | BIGINT PK | Auto increment |
| `query` | VARCHAR(500) | Το ερώτημα του χρήστη |
| `matched_index_id` | BIGINT | ID της πρώτης αντιστοίχισης |
| `cache_hit` | TINYINT | 1 αν απαντήθηκε από cache |
| `response_time_ms` | INT | Χρόνος απόκρισης σε ms |
| `created_at` | DATETIME | Timestamp |

---

## Ευρετηρίαση (Indexer)

Κατά το re-index, για κάθε published page:

1. **Τίτλος** → από `post_title`
2. **URL** → από `get_permalink()`
3. **Περιγραφή** (fallback chain):
   - Excerpt (αν υπάρχει)
   - Yoast meta description (`_yoast_wpseo_metadesc`)
   - Πρώτες 30 λέξεις από το content (stripped)
   - Μόνο τίτλος (last resort)
4. **Keywords** → εξαγωγή από τίτλο + content, αφαίρεση stop words, top-20 μοναδικές λέξεις

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
| Initial re-index (~200 σελίδες) | ~$0.00 (Φάση 1 δεν καλεί API για index) |
| 100 αναζητήσεις/ημέρα (0% cache) | ~$0.05/ημέρα |
| 100 αναζητήσεις/ημέρα (90% cache) | ~$0.005/ημέρα |
| **~$1–2 / μήνα** για τυπικό δήμο | ✓ |

---

## Φάσεις Ανάπτυξης

### ✅ Φάση 1 — MVP (Ολοκληρώθηκε)
- Plugin scaffold + activation
- Custom tables
- Basic indexer (WP pages, χωρίς AI enhancement)
- Claude API integration
- Basic search (top-50 → Claude)
- Shortcode + responsive frontend
- Admin settings (API key, site name)
- AES-256 encryption
- Rate limiting + security hardening

### Φάση 2 — Intelligence
- AI Enhancement: Claude βελτιώνει descriptions + keywords κατά το indexing
- SQL pre-filtering με `LIKE` (top-15 candidates αντί top-50)
- Caching με WP transients (24h TTL)
- Elementor shortcode stripping

### Φάση 3 — Production-Ready
- External links (non-WP pages)
- Auto re-index on `save_post`
- Multiple results display
- Σφάλματα + logging βελτιώσεις

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
- **Namespace:** `AISearch`
- **Prefix:** `ais_`

---

## Uninstall

Κατά τη **διαγραφή** (όχι απλά deactivation) του plugin:
- Drops `wp_ai_search_index` και `wp_ai_search_log`
- Διαγράφει όλα τα options (`ais_*`)
- Καθαρίζει τα cached transients

---

## Changelog

### 1.0.0
- Initial MVP release
