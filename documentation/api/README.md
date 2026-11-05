# TAAS API

The REST API for the TA application and matching system, implemented in the `taas-core` plugin.

This page covers the rules shared by every route. The routes themselves are split by who uses them:

| Document | Covers |
| --- | --- |
| [common.md](common.md) | Routes for any signed-in user: current user, application cycle, course listings, status |
| [admin.md](admin.md) | Routes for the admin: application cycle, courses, professors, students, applications, matching |
| [professor.md](professor.md) | Routes for a signed-in professor: their courses, applicants and rankings |
| [student.md](student.md) | Routes for a signed-in student: applying, and checking or editing their applications |

HR has no routes of its own. It uses the routes in [common.md](common.md), during its stage.

## All routes

Paths are relative to the base path, `/wp-json/taas/v1`.

| Method | Path | Access | Document |
| --- | --- | --- | --- |
| GET | `/me` | Signed in, any stage | [common.md](common.md#get-current-user) |
| GET | `/application-cycle` | Signed in, any stage | [common.md](common.md#get-current-application-cycle) |
| GET | `/courses` | Signed in | [common.md](common.md#get-course-listings) |
| GET | `/courses/{course_id}` | Signed in | [common.md](common.md#get-one-course) |
| GET | `/status` | Signed in | [common.md](common.md#get-status) |
| PUT | `/application-cycle` | Admin | [admin.md](admin.md#put-set-application-cycle) |
| PATCH | `/application-cycle` | Admin | [admin.md](admin.md#patch-change-dates) |
| POST | `/courses` | Admin | [admin.md](admin.md#post-add-a-course) |
| PATCH | `/courses/{course_id}` | Admin | [admin.md](admin.md#patch-edit-a-course) |
| DELETE | `/courses/{course_id}` | Admin | [admin.md](admin.md#delete-delete-a-course) |
| GET | `/professors` | Admin | [admin.md](admin.md#get-professors) |
| GET | `/professors/{professor_id}` | Admin | [admin.md](admin.md#get-one-professor) |
| GET | `/students` | Admin | [admin.md](admin.md#get-students) |
| GET | `/students/{student_ufid}` | Admin | [admin.md](admin.md#get-one-student) |
| GET | `/student-profiles` | Admin | [admin.md](admin.md#get-student-profiles) |
| GET | `/student-profiles/{student_ufid}` | Admin | [admin.md](admin.md#get-one-student-profile) |
| GET | `/academic-backgrounds` | Admin | [admin.md](admin.md#get-academic-backgrounds) |
| GET | `/academic-backgrounds/{background_id}` | Admin | [admin.md](admin.md#get-one-academic-background) |
| GET | `/applications` | Admin | [admin.md](admin.md#get-applications) |
| GET | `/applications/{application_id}` | Admin | [admin.md](admin.md#get-one-application) |
| PATCH | `/applications/{application_id}` | Admin | [admin.md](admin.md#patch-make-change-or-undo-a-match-or-reject-an-applicant) |
| GET | `/matching/recommendations` | Admin | [admin.md](admin.md#get-recommended-matches) |
| GET | `/matching/recommendations/{course_id}` | Admin | [admin.md](admin.md#get-recommended-matches-for-one-course) |
| GET | `/matching/courses` | Admin | [admin.md](admin.md#get-current-matches-by-course) |
| GET | `/matching/courses/{course_id}` | Admin | [admin.md](admin.md#get-current-matches-for-one-course) |
| GET | `/matching/courses/{course_id}/applicants` | Admin | [admin.md](admin.md#get-a-courses-applicants) |
| GET | `/matching/students` | Admin | [admin.md](admin.md#get-current-matches-by-student) |
| GET | `/matching/students/{student_ufid}` | Admin | [admin.md](admin.md#get-current-matches-for-one-student) |
| GET | `/matching/progress` | Admin | [admin.md](admin.md#get-matching-progress) |
| POST | `/matching/finalize` | Admin | [admin.md](admin.md#post-finalize-matches) |
| GET | `/professor/courses` | Professor | [professor.md](professor.md#get-a-professors-courses) |
| GET | `/professor/courses/{course_id}/applications` | Professor | [professor.md](professor.md#get-applicants-for-a-course) |
| GET | `/professor/courses/{course_id}/ranking` | Professor | [professor.md](professor.md#get-current-ranking) |
| POST | `/professor/courses/{course_id}/ranking` | Professor | [professor.md](professor.md#post-submit-the-ranking) |
| GET | `/professor/applications/{application_id}` | Professor | [professor.md](professor.md#get-one-applicants-details) |
| GET | `/student/application` | Student, any stage | [student.md](student.md#get-application-form) |
| POST | `/student/application` | Student | [student.md](student.md#post-submit-an-application) |
| GET | `/student/application/terms` | Student, any stage | [student.md](student.md#get-a-students-terms) |
| GET | `/student/application/{semester}/{year}` | Student, any stage | [student.md](student.md#get-one-application) |
| PATCH | `/student/application/{semester}/{year}` | Student | [student.md](student.md#patch-edit-an-application) |

## Conventions

| | |
| --- | --- |
| Base path | `/wp-json/taas/v1` |
| Format | JSON in and out. Send `Content-Type: application/json` when there is a body |
| Field names | Database column names wherever a column exists |
| Nulls | A field marked *nullable* can be `null`. This means no value, not an error |

**UFIDs** are strings of up to 8 characters, so don't treat them as numbers. The caller is identified by their session, never by a UFID in the request.

**Terms in requests** use `?term=Spring&year=2027`. `term` is `Fall`, `Spring`, `SummerA`, `SummerB` or `SummerC`. Case, spaces, `_` and `-` are ignored, so `Summer A` and `summer-a` also work. `year` is from 1901 to 2155.

On routes where the term is optional, send both or neither. Sending neither uses the current application cycle's term, and returns 404 `taas_no_current_cycle` if none has been set. Sending only one returns 400 `rest_missing_callback_param`.

**Terms in responses** are `Fall`, `Spring`, `Summer A`, `Summer B` or `Summer C`.

**Stored values** are always sent as labels and real booleans, never as the integers in the database:

| Field | Values |
| --- | --- |
| `match_status` | `Pending`, `Matched`, `Rejected`, `Withdrawn` |
| `enrollment_status` | `Not enrolled`, `Undergraduate`, `Masters`, `PhD` |
| `degree_type` | `AA`, `BS`, `MS`, `PhD` |
| `eap5836_status`, `eap5837_status` | `Taken at UF`, `Taken elsewhere`, `Currently taking`, `Not taken` |
| `stage` | `student_application`, `hr_review`, `professor_ranking`, `admin_matching` |

**Timestamps** in the application cycle are UTC, in the form `2026-11-16T04:59:59Z`. Other formats are rejected, including UTC offsets. Convert to Eastern for display, and convert Eastern input to UTC before sending. **Dates** on student records are `YYYY-MM-DD`.

The student routes in [student.md](student.md) don't follow all of these. That page lists the differences.

**Lists** with pagination are plain JSON arrays and accept `?page=` (default 1) and `?per_page=` (default 50, from 1 to 100). Totals are in the `X-WP-Total` and `X-WP-TotalPages` response headers. No results is 200 with `[]`. Each route says whether it is paginated.

**Single items** return one object, or 404 `taas_not_found` if it does not exist. An id in the wrong format, such as a non-numeric application id, doesn't match any route and returns 404 `rest_no_route`. Ids are only read from the URL, and an id in the query string or body is ignored.

## Sign-in

| Environment | How it works |
| --- | --- |
| Production | A WordPress cookie session through Shibboleth. WordPress also expects an `X-WP-Nonce` header on every request, GET included, and treats a request without a valid one as signed out. Route requests through one shared fetch wrapper, so the header is added in one place |
| Local | Mocked, with no nonce needed. Add `?taas_role=` to a URL, or set `TAAS_LOCAL_DEV_USER` in `.env` |

| Local role | Role | Mock UFID |
| --- | --- | --- |
| `student` | Student | `12345678` |
| `instructor` | Professor | `87654321` |
| `hr` | HR | `11223344` |
| `admin` | Admin | `99887766` |

Professors hold the `taas_instructor` role, so local requests use `?taas_role=instructor`.

A user can hold several roles, such as an admin who also teaches, and can use the routes of each. WordPress administrators hold every role, worked out on each request rather than saved. In production, the UFID comes from Shibboleth at sign-in. At sign-in, and every 10 minutes after, the student role is given or removed to match the `eduperson_affiliations` attribute (UF sends `STUDENT$`), and the professor role to match the professors table. If the attribute is missing, the student role is left as it is. Course import adds professors by email without a UFID, so a professor's first sign-in saves their UFID to the professors row whose email matches their Shibboleth `mail` or `<glid>@ufl.edu`.

## Stage access

A cycle is one term's run through four stages, and at most one stage is active at a time. [The application cycle](common.md#application-cycle) explains how the dates decide the stage.

| Order | `stage` | What happens | Who has access |
| --- | --- | --- | --- |
| 1 | `student_application` | Students apply | Students and admin |
| 2 | `hr_review` | HR validates students | HR and admin |
| 3 | `professor_ranking` | Professors rank students and add notes | Professors and admin |
| 4 | `admin_matching` | Admin handles final matches | Admin only |

When `stage` is `null`, or no cycle has been set, only the admin has access.

A caller with several roles passes this check if any one of their stages is active, and an admin always passes it. The check doesn't look at which route is called, so such a caller can also use their other roles' routes in that stage. For example, a professor who is also a student can use `/professor` routes during `student_application`.

Admin routes work in every stage. Outside their stage, students, HR and professors get this error from every route except these GETs, which stay open in every stage:

- `GET /me` and `GET /application-cycle`, so pages can show a "closed" message
- the student's own application routes, `GET /student/application`, `GET /student/application/terms` and `GET /student/application/{semester}/{year}`, so students can check their status after their stage

The route's own role check still applies to these. `data.stage` is the current stage, or `null` if none is active:

```json
{
  "code": "taas_stage_closed",
  "message": "This part of the system is not open right now.",
  "data": { "status": 403, "stage": "hr_review" }
}
```

This check runs first, so outside their stage a caller gets `taas_stage_closed` even if the request is invalid or the route isn't for their role.

## Errors

Every error has the standard WordPress shape. Use `code` and the HTTP status to decide what to display, since `message` is for debugging.

```json
{
  "code": "taas_not_found",
  "message": "No such record.",
  "data": { "status": 404 }
}
```

Invalid fields include details by field in `data.params`:

```json
{
  "code": "rest_invalid_param",
  "message": "Invalid parameter(s): lab_hours",
  "data": {
    "status": 400,
    "params": { "lab_hours": "lab_hours must be 0, 1 or 2." }
  }
}
```

These apply to every route. Other errors are listed with their routes.

| Status | `code` | When |
| --- | --- | --- |
| 400 | `rest_invalid_json` | The body isn't valid JSON |
| 400 | `rest_missing_callback_param` | A required field is missing. `data.params` lists the fields |
| 400 | `rest_invalid_param` | A field has the wrong type or is out of range. `data.params` gives the reason for each field |
| 401 | `taas_unauthorized` | Nobody is signed in, on a route for any signed-in user |
| 401 | `taas_forbidden` | Nobody is signed in, on a route for one role |
| 403 | `taas_forbidden` | Signed in, but with the wrong role for this route |
| 403 | `taas_stage_closed` | Outside the caller's stage. See [Stage access](#stage-access) |
| 404 | `taas_not_found` | The record does not exist |
| 404 | `rest_no_route` | The URL doesn't match any route |
| 500 | `taas_server_error` | A database failure or a bad stored row. Details go to the server log, not the response |

## Changelog

Changes to the rules on this page, newest first.

| Date | Change |
| --- | --- |
| 2026-11-04 | The student role is synced from Shibboleth's `eduperson_affiliations` |
| 2026-11-04 | WordPress administrators hold every role, not just admin |
| 2026-11-03 | A user can hold several roles. The stage check passes if any of their stages is active, and WordPress administrators count as admin |
| 2026-11-02 | Added the student routes. Their GETs stay open in every stage, so students can check their status after `student_application` |
