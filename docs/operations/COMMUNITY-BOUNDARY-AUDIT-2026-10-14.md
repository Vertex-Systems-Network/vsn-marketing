# Community boundary audit repair — 2026-10-14

Bounded correctness exception to the enterprise research frontier: protected main's Task0078CommunityBoundaryTest.php contains only a historical failed sed-command message, so it defines no PHP tests and cannot support its named boundary. Git commit88da0096 contains the intended regression suite on an unmerged historical branch. Restore that suite and extend adverse identity/provenance coverage; preserve all other tests.

The existing community contract requires HTTPS provenance, but a string-prefix check admits missing hosts and malformed URLs. Validate complete HTTPS URL syntax and reject control/space characters, without fetching provenance URLs or changing provider capabilities. Tests cover comments/mentions/messages, empty or whitespace tenant/provider/external/author/body fields, non-HTTPS, missing host and newline injection. This repair grants no live community, provider, compliance or Phase16 authority.

Architecture source-integrity coverage now rejects PHP test files that contain only command output, preventing silent loss of tests. Local validation: full backend suite1074 passed/154 infrastructure skips/7249 assertions; focused community2 passed/18 assertions; PHPStan and Pint passed. New architecture integrity gate is checked separately and required in existing architecture CI.
