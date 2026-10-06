# TASK-0077 Research Pack — scoped social publication contracts

- researched_at: 2026-10-04T18:07:00Z
- task: TASK-0077
- phase: PHASE-13
- researcher: Supervisor

## Official sources and decisions

| Source | Finding / implementation impact |
|---|---|
| [LinkedIn Posts API](https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-09) | Versioned Posts API; `w_organization_social` requires organization roles; commentary partial update and DELETE are documented. No generic media, native schedule or analytics claim. |
| [TikTok Direct Post](https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post) | `video.publish`, creator-info privacy options, explicit creator choice, URL ownership, audit and asynchronous publish ID are required. |
| [TikTok sharing policy](https://developers.tiktok.com/docs/en/content-sharing-guidelines?enter_method=left_navigation) | A utility for accounts managed by the developer/team is not an acceptable intended use; no internal-only upload adapter. |
| [TikTok status](https://developers.tiktok.com/docs/en/content-posting-api-reference-get-video-status) | Publish IDs require status reconciliation; accepted upload is not proof of publication. |
| [YouTube videos.insert](https://developers.google.com/youtube/v3/docs/videos/insert) | `youtube.upload` is required; unverified projects created after 2020-07-28 are private-only. Public visibility is not inferred. |
| [Threads permissions](https://developers.facebook.com/documentation/threads/get-started) | `threads_basic` and `threads_content_publish` are separate product permissions. |
| [Pinterest quickstart](https://github.com/pinterest/api-quickstart/blob/main/python/README.md) | Board ownership and `pins:write` are account-specific; sandbox evidence cannot prove public publication. |

## Plan reconciliation

Implement operation-level, offline-only capability candidates first. LinkedIn organic text is the first narrow lane; other platform/account/media combinations remain explicit unknown until concrete official evidence is bound. Existing canonical approval, tenant isolation, publication attempts, status reconciliation and quota evidence remain mandatory. Unknown scope, role, review, freshness, media, account type or operation fails closed. No transport, credential acquisition, app review, live publication or production quota is enabled.

## Required tests and risks

Tests must prove exact platform/operation mappings, stale/missing scope and role denial, policy restrictions, asynchronous/ambiguous reconciliation and no fallback. Recheck LinkedIn version before its October 2026 sunset and revalidate each provider before activation. This pack is architecture evidence, not live connector certification.
