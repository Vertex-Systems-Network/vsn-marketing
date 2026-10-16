# TASK-0097 acceptance matrix

| Criterion | Implementation | Evidence |
| --- | --- | --- |
| AC-1 permission inheritance and sibling-client isolation | `DelegatedAdministration`: client admin (`agency.manage`) grants a bounded permission set to one agency workspace; only agency members holding `agency.manage` inherit it, only for that client, only while unrevoked; billing, identity, provider credentials, privacy, audit export and re-delegation are non-delegable even if a stored link is tampered | `DelegatedAdministrationTest` inheritance/revocation, sibling client, agency staff, cross-client and tampered-link cases |
| AC-2 branding cannot spoof security origin, consent, ownership or billing | `BrandCustomizationPolicy` accepts only display name, hex colour and HTTPS logo; reserved security/consent/billing/ownership wording, domains and markup are rejected; `presentation()` always appends system-owned origin and disclosures | rejection dataset (11 spoof cases) and presentation assertions |
| AC-3 delegated-administration, cross-client and accessibility tests | Feature tests above; `resources/js/components/agency/brand-preview.test.tsx` checks landmark, heading, alt text and disclosure list via accessible roles | Exact-head CI |
