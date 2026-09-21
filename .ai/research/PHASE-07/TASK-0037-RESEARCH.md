# TASK-0037 Research Pack

- researched_at: 2026-09-21T12:14:00+00:00
- task: TASK-0037
- phase: PHASE-07
- scope: current cross-channel publishing APIs, account/app eligibility, app review/scopes, scheduling ownership, media transfer/processing constraints, and mature campaign/calendar approval workflows
- researcher: OpenAI development agent under Supervisor control
- status: acceptance candidate for PHASE-07 research-first gate

## Sources

| Source | Type | Version/date | Accessed | Why authoritative |
|---|---|---|---|---|
| https://developers.tiktok.com/docs/en/content-posting-api-get-started | TikTok official developer docs | updated 2026-08-04 | 2026-09-21 | Current Direct Post onboarding/audit and creator-info flow |
| https://developers.tiktok.com/docs/en/content-posting-api-reference-direct-post | TikTok official developer docs | current 2026-08 | 2026-09-21 | Direct Post endpoint, scope, privacy/caption and request-limit contract |
| https://developers.tiktok.com/docs/en/content-posting-api-reference-upload-video | TikTok official developer docs | updated 2026-08-04 | 2026-09-21 | Upload-without-posting workflow and user handoff requirement |
| https://www.postman.com/meta/instagram/documentation/6yqw8pt/instagram-api | Meta official Instagram Postman workspace | current 2026-09 | 2026-09-21 | Professional-account eligibility, permissions, Reels container/status/publish workflow and media constraints |
| https://www.postman.com/meta/instagram/folder/6raa77c/instagram-api-with-instagram-login | Meta official Instagram Postman workspace | current | 2026-09-21 | Current Instagram Login scope names and deprecation notice |
| https://www.postman.com/meta/facebook/documentation/r56bjfd/facebook-api | Meta official Facebook Postman workspace | current | 2026-09-21 | Facebook Page Reels upload/publish flow and DRAFT/SCHEDULED/PUBLISHED state |
| https://learn.microsoft.com/en-us/linkedin/marketing/community-management/shares/posts-api?view=li-lms-2026-03 | LinkedIn official Microsoft Learn | 2026-03 API view | 2026-09-21 | Current Posts API, version header, organic content types, role/scopes |
| https://developers.google.com/youtube/v3/docs/videos | YouTube official developer docs | current 2026 | 2026-09-21 | Video state, publishAt constraints and processing status |
| https://developers.google.com/youtube/v3/docs/videos/insert | YouTube official developer docs | current 2026 | 2026-09-21 | Upload scopes, audit restriction, media limits and quota bucket |
| https://developers.google.com/youtube/v3/revision_history | YouTube official revision history | 2026-06 updates | 2026-09-21 | Granular quota-system freshness evidence |
| https://github.com/xdevplatform/docs/blob/main/x-api/posts/manage-tweets/introduction.mdx | X official developer documentation repository | current | 2026-09-21 | Current create/delete Post endpoints and user-context prerequisites |
| https://github.com/xdevplatform/samples/blob/main/python/posts/create_post.py | X official developer samples | current | 2026-09-21 | OAuth2 PKCE write scopes and refresh-token scope example |
| https://github.com/xdevplatform/samples/blob/main/python/media/media_upload_v2.py | X official developer samples | current | 2026-09-21 | v2 media.write, chunked video upload and post handoff |
| https://support.buffer.com/en-us/articles/setting-up-your-timezones-and-posting-schedules-P4iSag90Fl | Buffer official Help Center | current | 2026-09-21 | Per-channel timezone, queue slots, fixed-time scheduling and DST workflow |
| https://support.buffer.com/en-us/articles/creating-managing-and-approving-draft-posts-on-the-buffer-mobile-app-XSRim1JeUl | Buffer official Help Center | current | 2026-09-21 | Draft/approval/queue transition patterns |
| https://support.sproutsocial.com/hc/en-us/articles/205974715-Message-Approval-Workflows | Sprout Social official support | current | 2026-09-21 | Approval workflow, edit/reschedule, missed approval deadline and role behavior |
| https://support.sproutsocial.com/hc/en-us/articles/38373940164877-Troubleshooting-Sprout-Social-Publishing-Calendar-Issues | Sprout Social official support | current | 2026-09-21 | Calendar status, approval expiry and connection-failure behavior |

## Current external reality

### TikTok Content Posting API

TikTok Direct Post requires Content Posting API configuration and the user-granted `video.publish` scope. The current Direct Post docs state that content from unaudited clients is restricted to private viewing until the client passes TikTok audit. The posting flow requires creator-info lookup first so privacy options and creator-specific limits come from current runtime evidence rather than hardcoded assumptions. Direct Post initialization is rate-limited per user access token and the supplied privacy value must match current creator-info output.

TikTok also exposes an Upload flow that deliberately does not complete publication: the user is notified in TikTok and must finish editing/posting there. VSN therefore needs separate capability outcomes for direct publication versus provider-side handoff/draft, not one boolean `can_publish`.

Remote pulls require provider-compatible HTTPS media URLs under verified ownership rules. Media transfer and processing status must be modeled separately from canonical asset identity.

### Meta / Instagram / Facebook

Meta's current Instagram API material supports professional Business/Creator accounts, while consumer accounts are excluded. Facebook Login and Instagram Login expose different permission models. The current Instagram Login scope values use `instagram_business_basic` and `instagram_business_content_publish`; the older `business_*` names were deprecated on 2025-01-27. Scope names therefore require versioned/effective-dated evidence and cannot be embedded permanently into core campaign rules.

Instagram Reels publication is multi-stage: create a media container from a provider-fetchable public URL, poll container status until ready, then call `media_publish`. The reviewed current material documents MOV/MP4, AAC audio, HEVC/H.264, 23–60 fps, maximum horizontal resolution 1920, 3 seconds to 15 minutes, and maximum 1 GB for the Reels example. These are provider/version capability facts, not global media constants.

The reviewed Facebook Page Reels material has its own upload-session and publish flow and exposes `DRAFT`, `SCHEDULED`, and `PUBLISHED` video states. This proves provider-native scheduling can exist, but does not justify assuming every provider exposes equivalent semantics.

### LinkedIn

The LinkedIn Posts API replaces `ugcPosts` and requires a `Linkedin-Version: YYYYMM` header plus Rest.li protocol version. Current organic support includes text, images, videos, documents, articles, multi-image, polls and celebrations; organic carousel is not supported in the reviewed matrix.

Organization posting requires `w_organization_social` and a qualifying organization Page role; member posting uses `w_member_social`. Read/write access and roles are therefore target-account capability evidence, not generic workspace permission alone.

The reviewed Posts API describes create/retrieve publication surfaces and does not establish one provider-native future scheduling field equivalent to YouTube `publishAt`. VSN must own canonical due-time semantics unless current provider capability evidence explicitly proves otherwise.

### YouTube

YouTube `videos.insert` uploads media and permits metadata including `status.publishAt`. `publishAt` is valid only when the video is private and has never been published; a time in the past publishes immediately. Unverified API projects created after 2020-07-28 have uploads restricted to private until the project passes audit.

The current insert reference documents a 256 GB maximum file size, supported `video/*` or `application/octet-stream` upload MIME types, OAuth upload scopes, and a dedicated uploads quota bucket. YouTube's 2026 revision history shows quota policy is actively evolving, reinforcing that quotas are effective-dated capability evidence.

Video processing is asynchronous and observable through `processingDetails.processingStatus`; VSN must not treat upload acknowledgement as final publication readiness.

### X

Current X developer documentation uses `POST /2/tweets` to create a Post and requires an approved developer account plus user-context authorization. X's official OAuth2 PKCE example requests `tweet.read`, `tweet.write`, `users.read`, and `offline.access`. The current official media v2 sample adds `media.write` and demonstrates that media upload precedes the create-Post operation.

The reviewed create-Post surface is immediate publication and does not establish a provider-native schedule timestamp. VSN must therefore own canonical scheduling for X unless a future, versioned capability explicitly proves native scheduling support.

Provider media IDs are derivative/transient references. Video/media processing may be asynchronous; a publication attempt must wait for provider-ready state and reconcile failure without replacing canonical asset identity.

## Market/reference workflow

### Buffer

Buffer separates queue scheduling from fixed date/time scheduling. Each connected channel owns its own timezone and recurring weekly posting schedule; queued content takes the next available slot, while fixed date/time posts stay at their explicit instant. Buffer also notes automatic DST handling for channel timezones.

Buffer's approval workflow preserves draft/pending-approval distinctions. Content with a fixed date can be approved-and-scheduled, while queue-oriented content can be approved-and-added-to-queue. This supports a VSN model where scheduling strategy is explicit rather than inferred from one timestamp field.

### Sprout Social

Sprout exposes distinct Draft, Needs Approval, Scheduled, Rejected/Failed and publishing-calendar states. Submitted content can be edited while awaiting approval; schedule changes are auditable workflow events.

If a scheduled item is not approved in time, it does not publish. Current help material describes expiry/rejection behavior rather than silently publishing late. This is a strong fail-closed pattern for VSN.

Publishing calendar views and connection troubleshooting also show that provider/account disconnect must be represented as operational state. A canonical campaign should remain intact even when one target connection is unavailable.

## Security/privacy findings

1. Provider credentials stay behind secret references and adapter boundaries; no access/refresh token is embedded in campaign, calendar or content snapshots.
2. Remote media fetch capability is a network-security boundary. Only authorized, workspace-owned/verified asset delivery URLs may be exposed to providers; arbitrary user URLs must not become server-side fetch authority.
3. Publication authorization requires both VSN RBAC/approval and current provider/account capability evidence. One cannot substitute for the other.
4. Approval must bind to an immutable campaign/content snapshot identity. A material edit after approval invalidates or re-evaluates approval rather than inheriting it silently.
5. Provider temporary upload/container/media IDs are non-authoritative derivatives and may expire or fail processing.
6. Publication attempts need idempotency keys, immutable request snapshots and reconciliation state to prevent duplicate external posts during retries or ambiguous network failures.
7. Webhook/status events may be delayed, duplicated or out of order. Provider observations must not rewrite canonical history.
8. Cross-workspace campaign, target-account, asset, approval and publication-attempt references fail closed.

## API/platform constraints

| Platform | Eligibility / auth evidence | Scheduling finding | Media / async finding |
|---|---|---|---|
| TikTok | Content Posting API, `video.publish`, client audit for public visibility | No universal native scheduling contract established in reviewed Direct Post docs; VSN owns due time | Direct Post/upload split; creator-info/runtime limits; remote pull ownership; status reconciliation |
| Instagram | Professional accounts; Facebook or Instagram Login permission sets; current `instagram_business_content_publish` for Instagram Login | No universal provider-native future schedule field established in reviewed publication flow | Container -> status -> publish; public provider-fetchable media URL; provider-specific Reels constraints |
| Facebook Page Reels | Page token/account capability | Reviewed API exposes `SCHEDULED` state | Upload session and processing/publish phases |
| LinkedIn | Versioned API header; member/org write permissions and Page roles | No generic native schedule field established in reviewed Posts API | Content-type specific upload/reference flows; role/capability dependent |
| YouTube | OAuth upload scope; API-project audit can restrict public visibility | Native `status.publishAt` with private/never-published constraints | Large media upload; async processing; granular quota bucket |
| X | Approved developer app; user-context OAuth; `tweet.write`; `media.write` for v2 media sample | Reviewed create Post endpoint is immediate; VSN owns due time absent new evidence | Upload media first; processing before attach; provider media ID is derivative |

## Performance/reliability findings

- Provider publish calls are network effects and cannot be committed atomically with VSN database state. Use durable publication intents/attempts and reconciliation.
- Media upload/processing can outlive the initial request. Scheduler workers need bounded polling/backoff and recoverable state, not long synchronous locks.
- Provider limits are not uniform and can change independently. Persist capability evidence with source/version/effective/freshness metadata.
- Multi-channel campaign execution is partial-failure by nature. One target succeeding must not roll back external reality or force other targets to appear successful.
- Exact scheduled time should have an explicit tolerance/SLO measured later; TASK-0037 does not invent one before production-representative evidence.
- Queue/next-slot scheduling and fixed-time scheduling are distinct semantics and must survive timezone changes deterministically.
- DST behavior must be defined using IANA timezone identifiers and immutable resolved execution instants, with explicit policy for nonexistent/ambiguous local times in TASK-0039.

## Conflicts with current assumptions

- A single provider-neutral `publish_at` that is simply forwarded to every provider is invalid. Provider-native scheduling availability differs.
- Upload success is not publication success. TikTok, Instagram, YouTube and X media workflows can include additional processing/status phases.
- One global social-media permission is invalid. Provider scopes, app audit/review and target-account roles are materially different.
- Provider media/container identifiers cannot be durable canonical asset identity.
- Approval cannot be a mutable boolean attached only to a campaign ID; it must bind to the exact content/target snapshot being authorized.

## Required roadmap extensions

No new task is required before TASK-0038. The preplanned TASK-0038 through TASK-0042 sequence can absorb the current findings, but their acceptance criteria must include:

- immutable campaign/publication snapshot identity and approval invalidation on material edits;
- provider/account capability evidence with version/effective/freshness and app-review/audit state;
- explicit schedule strategy: fixed instant, VSN queue slot, or provider-native schedule when proven;
- durable publication attempt/idempotency/reconciliation and partial-success state;
- provider media preparation state separated from publication state;
- timezone/DST and missed-approval/missed-run semantics;
- cross-workspace and remote-media-fetch security tests.

Disposition: `NEW_ACCEPTANCE_CRITERION` for TASK-0038 through TASK-0042, not `NEW_TASK`.

## Rejected options

- **Provider scheduler as canonical calendar** — rejected because providers differ and provider-side state can drift or become unavailable.
- **One global media-limits table hardcoded in core logic** — rejected because formats/limits are provider/version/effective-date capability data.
- **Publish first, reconcile approval later** — rejected because approval is deterministic authority and must precede the external side effect.
- **Long-lived canonical provider upload IDs** — rejected because provider references can expire, fail processing or be recreated.
- **Treat market-tool workflow as the domain model** — rejected; Buffer/Sprout are workflow references, not canonical schema authorities.
- **Run live provider tests during research** — rejected; TASK-0037 is evidence/reconciliation only.

## Decision impact

- `CONFIRMS_PLAN`: TASK-0038 campaign lifecycle/approvals, TASK-0039 calendar/scheduler, TASK-0040 provider-neutral publication lifecycle, TASK-0041 operator UX and TASK-0042 certification remain the correct sequence.
- `NEW_ACCEPTANCE_CRITERION`: bind approvals to immutable snapshots; model provider scheduling as capability; separate media processing from publish attempts; reconcile partial failure.
- `NEW_PREREQUISITE`: provider connector activation requires current app-review/audit, write-scope, account-role and media capability evidence.
- `NO_PRODUCT_IMPACT`: no new module boundary is required; the accepted Publishing boundary remains suitable.
- `ADR_REQUIRED`: no new ADR is required at this research stage.
- `BLOCKER`: none for research activation.
- `DEFER_WITH_APPROVAL`: live provider publication/sandbox writes remain deferred until the corresponding implementation/operational approval task.

## Freshness risks

TikTok audit policy, Meta scope names/Graph versions, LinkedIn Marketing API versions/roles, YouTube quota/audit rules, X API products/scopes and provider media limits can change quickly. Revalidate material claims on the exact TASK-0037 acceptance session and again before each concrete connector is production-enabled.

Do not infer support from old SDK examples or third-party blog posts when current official docs differ. Any provider-native scheduling feature not present in this pack must enter VSN only as newly researched, versioned capability evidence.

## Acceptance revalidation

The material provider/API and market-workflow claims in this pack were revalidated in the active 2026-09-21 research session against the current official sources listed above. No material conflict was found that requires a new PHASE-07 task before TASK-0038. The provider-specific scope, app-review/audit, media-processing and scheduling differences remain mandatory versioned capability evidence.

The research gate therefore freezes the following non-negotiable PHASE-07 boundaries:

1. canonical campaign, approval, immutable snapshot and intended execution time remain VSN authority;
2. provider-native scheduling is optional capability evidence and never a universal contract;
3. approval authority binds to the exact immutable content/target snapshot and must be re-evaluated after material edits;
4. provider upload/container/media references remain derivative and reconcilable;
5. media preparation and publication attempts are separate asynchronous lifecycle states;
6. publication attempts are idempotent, workspace-scoped and reconciliation-safe under retries, partial success and delayed/duplicate provider events;
7. provider app-review/audit, write scopes, target-account roles and media constraints fail closed when missing, stale or incompatible;
8. live provider posting, production schedule execution and provider credential activation remain outside TASK-0037.

## Frozen research disposition

The PHASE-07 preplanned sequence is confirmed with stricter acceptance boundaries. TASK-0037 can be registered as the research-first successor, but no publishing implementation is authorized until a separate guarded TASK-0036 -> TASK-0037 transition and exact-head research acceptance.
