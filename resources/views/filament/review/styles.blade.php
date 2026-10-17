<style>
.review-grid { display:grid; gap:1rem; grid-template-columns:repeat(auto-fit,minmax(16rem,1fr)); }
.review-stack { display:grid; gap:1rem; }
.review-scroll { overflow:auto; }
.review-table { border-collapse:collapse; width:100%; text-align:start; font-size:.9rem; }
.review-table th,.review-table td { padding:.8rem; border-bottom:1px solid #94a3b840; text-align:start; vertical-align:top; }
.review-table th { font-weight:600; white-space:nowrap; }
.review-muted { opacity:.7; font-size:.85em; }
.review-filter { display:flex; flex-direction:column; gap:.35rem; }
.review-filter select,.review-filter input { border:1px solid #94a3b880; padding:.55rem; border-radius:.5rem; color:inherit; background:transparent; }
.review-outcome { border-inline-start:4px solid #64748b; padding-inline-start:.7rem; }
.review-outcome[data-result="met"] { border-color:#16a34a; }
.review-outcome[data-result="not_met"] { border-color:#dc2626; }
.review-outcome[data-result="not_mentioned"] { border-color:#d97706; }
.review-outcome[data-result="unclear"] { border-color:#7c3aed; }
.review-preview { white-space:pre-wrap; overflow-wrap:anywhere; max-height:32rem; overflow:auto; padding:1rem; background:#94a3b810; border-radius:.6rem; }
.review-preview mark { background:#fde047; color:#111827; scroll-margin:5rem; }
.review-diff { background:#fbbf2430; }
.review-number { direction:ltr; unicode-bidi:isolate; font-variant-numeric:tabular-nums; }
.review-table meter { width:7rem; height:.8rem; }
</style>

