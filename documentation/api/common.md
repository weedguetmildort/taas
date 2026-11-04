# Common Routes

Routes for any signed-in user. The base path, sign-in, stage access and shared errors are in the [API overview](README.md).

## Current user

### GET: current user

`GET /me`

**Access:** any signed-in user, in any stage

**Request params:** none, uses the session

**Response body:**

```json
{
  "name": "Dr. Smith",
  "is_admin": false,
  "is_hr": false,
  "is_professor": true,
  "is_student": false
}
```

These flags only decide what a page shows, since the server still checks each request. A user can hold several roles, so more than one flag can be `true`. A signed-in user with no TAAS role has them all `false`.

## Application cycle

A cycle is one term's run through four stages. The admin controls the stages by setting their dates in [admin.md](admin.md#put-set-application-cycle), and the current stage is worked out from those dates on each request. One stage ending never starts the next: a stage only starts when its own `start_at` comes.

### GET: current application cycle

`GET /application-cycle`

**Access:** any signed-in user, in any stage

**Request params:** none

**Response body:**

```json
{
  "term": "Spring",
  "year": 2027,
  "stage": "student_application",
  "stage_dates": {
    "student_application": {
      "start_at": "2026-10-15T04:00:00Z",
      "end_at": "2026-11-16T04:59:59Z"
    },
    "hr_review": {
      "start_at": "2026-11-16T05:00:00Z",
      "end_at": "2026-11-23T04:59:59Z"
    },
    "professor_ranking": {
      "start_at": "2026-11-23T05:00:00Z",
      "end_at": "2026-12-07T04:59:59Z"
    },
    "admin_matching": null
  }
}
```

Returns 404 `taas_no_current_cycle` if no cycle has been set.

`term` and `year` fill the `<TERM> <YEAR>` headings. `stage` decides who has access, and is `null` when no stage is active.

| Field | Meaning |
| --- | --- |
| `start_at` | From this time, that stage is the current stage. If several stages' `start_at` have come, the latest is current. `null` means the stage never starts |
| `end_at` | From this time, if that stage is the current one, `stage` is `null` until another stage's `start_at` comes. An earlier stage doesn't reopen. `null` means the stage has no set end, and runs until a later stage starts |

`stage_dates` always has all four stages. A stage's entry is `null` if it has no dates.

## Courses

### GET: course listings

`GET /courses`

**Access:** any signed-in user, during their stage. The admin has access in every stage.

**Paginated:** yes, ordered by `course_code`, then `course_name`

**Request params (all optional):**

| Param | Notes |
| --- | --- |
| `term`, `year` | Defaults to the current application cycle's term |
| `course_code` | Exact match. A code can return several courses, because one code such as CIS4930 can cover several topics |
| `professor_ufid` | Admin and HR only, and ignored for other callers |

**Response body:**

```json
[
  {
    "course_id": 101,
    "course_code": "COP3502C",
    "course_name": "Programming Fundamentals 1",
    "course_semester": "Spring",
    "course_year": 2027,
    "is_online": false,
    "course_description": null,
    "course_notes": null,
    "weekly_hours": 70,
    "lab_hours": 2,
    "enrollment": 175,
    "max_enrollment": 200,
    "professor_ufid": "87654321",
    "professor_name": "Dr. Smith",
    "professor_email": "smith@ufl.edu"
  }
]
```

| Field | Type | Notes |
| --- | --- | --- |
| `course_id` | integer | One offering of a course in one term |
| `course_description` | string, *nullable* | Up to 1000 characters |
| `course_notes` | string, *nullable* | The professor's notes for matching, up to 1000 characters |
| `weekly_hours` | integer, *nullable* | Total TA hours per week for the whole course, not per TA |
| `lab_hours` | integer | 0, 1 or 2 |
| `professor_ufid` | string | Only returned to admin and HR |

The server calculates `weekly_hours` from `enrollment` and `lab_hours`. It is `null` when `enrollment` is under 10.

```text
hours = enrollment * 10 / (35 with no lab, 30 with 1 hour, 25 with 2 hours)
then round to the nearest multiple of 5
```

### GET: one course

`GET /courses/{course_id}`

**Access:** same as GET: course listings

**Response:** one course, in the same shape as one item in GET: course listings, or 404 `taas_not_found`.

## Status

### GET: status

`GET /status`

**Access:** any signed-in user, during their stage. The admin has access in every stage.

A health check that shows routing, sign-in and the database connection are working.

**Request params:** none

**Response body:**

```json
{
  "version": "0.7.8.8.2.0",
  "local_dev": false,
  "roles": ["taas_admin", "taas_instructor"],
  "tables": {
    "professors": true,
    "students": true,
    "student_profiles": true,
    "academic_background": true,
    "courses": true,
    "applications": true,
    "application_cycle": true
  },
  "shibboleth": {
    "plugin_active": true,
    "glid": "albert",
    "ufid": "******78",
    "affiliations": ["faculty", "member"],
    "session_matches": true,
    "ufid_saved": "******78",
    "last_synced": 1793750400
  }
}
```

| Field | Notes |
| --- | --- |
| `version` | The plugin version |
| `local_dev` | Whether sign-in is mocked |
| `roles` | Every TAAS role the caller holds, in the order `taas_admin`, `taas_hr`, `taas_instructor`, `taas_student`. Empty if they have none |
| `tables` | Whether each database table exists |
| `shibboleth` | Only for the admin, which includes WordPress administrators, and not in local development. The admin's own Shibboleth session, to check what UF sends |

| `shibboleth` field | Notes |
| --- | --- |
| `plugin_active` | Whether the Shibboleth plugin is loaded |
| `glid` | GatorLink ID from the session |
| `ufid` | UFID from the session, all but the last two digits hidden. Empty if missing or not 8 digits |
| `affiliations` | Values of the `eduperson_affiliations` attribute, split on `;` |
| `session_matches` | Whether the session belongs to this WordPress account, by GatorLink ID or email |
| `ufid_saved` | The UFID saved to the account, masked the same way. Empty if none is saved |
| `last_synced` | Unix time of the last sync from Shibboleth, or `0` if never |

## Changelog

Changes to these routes, newest first.

| Date | Route | Change |
| --- | --- | --- |
| 2026-11-04 | `GET /me` | Every flag is `true` for WordPress administrators |
| 2026-11-03 | `GET /status` | Replaced `role` with `roles`, and added `shibboleth` for the admin |
| 2026-11-03 | `GET /me` | More than one flag can be `true` for a user with several roles |
