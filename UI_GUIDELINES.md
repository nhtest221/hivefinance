# UI Guidelines

## Design Direction

HiveFinance uses a dense, quiet enterprise SaaS interface inspired by Linear, Stripe Dashboard, and Vercel Dashboard. The UI prioritizes scanability, keyboard use, precise tables, clear hierarchy, and restrained visual weight for finance professionals working through repeated review tasks.

## Design Principles

1. Data first: tables, filters, totals, and status should be visible before decorative content.
2. Quiet hierarchy: use typography, spacing, and borders before heavy color.
3. Finance-safe interaction: destructive and posting-related actions must be visually distinct, even in mock layouts.
4. Context preservation: users should never lose entity, period, or module context.
5. Progressive density: desktop pages may show dense tables; mobile collapses into focused summary rows.

## Design Tokens Usage Review

Use the CSS tokens from `frontend/src/app/styles.css`:

| Token | Use |
|---|---|
| `--color-bg` | App background and page canvas |
| `--color-surface` | Panels, headers, cards, table surfaces |
| `--color-surface-subtle` | Subtle row hover, tab active state, secondary bands |
| `--color-border` | Default dividers and card/table borders |
| `--color-border-strong` | Form controls and high-emphasis separators |
| `--color-text` | Primary body and headings |
| `--color-text-muted` | Secondary labels and helper text |
| `--color-text-subtle` | Captions, overlines, quiet metadata |
| `--color-primary` | Primary action and active navigation |
| `--color-success` | Completed, reconciled, paid, healthy states |
| `--color-warning` | Attention, pending, review states |
| `--color-danger` | Errors, failed states, destructive affordances |
| `--shadow-raised` | Dialogs, drawers, menus, high elevation only |

Spacing follows the exported `spacing` token set: `xs`, `sm`, `md`, `lg`, `xl`, `2xl`. Use `md` as the default internal panel rhythm and `lg` for page section gaps.

## Component Usage Guidelines

| Component | Use | Avoid |
|---|---|---|
| `Button` | Commands, navigation affordances, table actions | Using as decorative pill text |
| `Input` / `Textarea` | Filters, search, forms | Hiding labels in complex forms |
| `Select` | Entity, period, status, category filters | Long free-text selection |
| `Checkbox` | Multi-select rows, boolean settings | One-of-many choices |
| `RadioGroup` | Mutually exclusive modes | Multi-select options |
| `DatePickerPlaceholder` | Reserved date selection affordance until date picker is implemented | Business date calculations |
| `Card` | Repeated summary or framed tool panels | Nesting cards inside cards |
| `Table` | High-density finance lists | Marketing-style data display |
| `Badge` | Status, basis labels, queue states | Primary action substitute |
| `Alert` | Persistent warning/info messages | Toast replacement |
| `Toast` | Short-lived confirmation or error feedback | Audit-critical records |
| `Dialog` | Blocking confirmations and small forms | Long multi-step workflows |
| `Drawer` | Context detail without leaving a list | Full-page replacement |
| `Tabs` | Peer views of the same object | Primary navigation |
| `Breadcrumbs` | Location context in deeper modules | Replacing sidebar navigation |
| `Sidebar` | Primary module navigation | Content-specific actions |
| `TopNavigation` | Entity, period, user, and global search area | Dense module-specific controls |
| `PageHeader` | Page title, description, primary actions | Hero/marketing content |
| State components | Loading, empty, error, skeleton placeholders | Business rule messaging |

## Page Composition

Every production page uses:

1. Sidebar for primary module navigation.
2. Top navigation for global search, active entity, active period, and user controls.
3. Page header with title, description, and at most two primary actions.
4. Filter bar for table-heavy screens.
5. Summary strip only when it helps financial scanning.
6. Main table or panel.
7. Optional right-side drawer for details.

## Tables

- Default to compact rows, sticky table headers where possible, and stable numeric alignment.
- Monetary values align right.
- Status and basis labels use badges.
- Important row metadata should remain visible without horizontal scrolling on desktop.
- Mobile table views collapse to stacked rows with the same ordering and labels.

## Keyboard Shortcuts

Global shortcuts:

| Shortcut | Action |
|---|---|
| `/` | Focus global search |
| `g d` | Dashboard |
| `g c` | Chart of Accounts |
| `g j` | Journal Entries |
| `g r` | Receivables |
| `g p` | Payables |
| `g s` | Settlement |
| `g b` | Bank Accounts |
| `g t` | Tax |
| `g f` | FX |
| `g x` | Reports |
| `g a` | Audit Log |
| `g ,` | Settings |
| `Esc` | Close dialog or drawer |

Shortcuts are documented for UI design only in M0.5; no shortcut behavior is implemented.

## Accessibility Review

- Color is never the only status signal; badges include text.
- Focus states must remain visible on controls.
- Interactive icons need accessible labels when used without text.
- Table headers must identify columns.
- Dialogs and drawers use Radix primitives for focus management.
- Text contrast should meet WCAG AA against the active surface token.
- Dense pages should preserve readable row height and avoid font sizes below 12px.
- Mobile layouts must preserve source order and avoid horizontal-only access to critical content.
