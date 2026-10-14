# Guided Work Descriptions

Guided work descriptions are deterministic sentence builders, not generative AI. A database-backed `GuidedDescriptionProfile` contains stable action codes, plain-language labels, deterministic clauses, outcome codes, and a version marker.

The seeded profiles are Electrical, Plumbing, Carpentry and Woodworking, Welding, Air-conditioning, General Maintenance, Masonry, Painting, Cleaning Surroundings, Comfort Room Cleaning, Garbage Disposal, Grass Cutting, and Generic Work. An unmapped or newly created Work Order category automatically uses the generic profile rather than failing or reverting to technical free text.

Staff select one or more actions and an outcome. `GuidedDescriptionService` validates those codes against the selected profile, preserves their order, joins the clauses, and appends the outcome sentence. For example:

- “Inspected the affected area, replaced the damaged electrical outlet, and tested the installation after repair. The reported issue is now working properly.”
- “Inspected the reported leak and checked the pipe connection. Work will continue when the required materials or parts are available.”
- “Prepared the surface for welding, welded the damaged joint, and checked the stability of the joint. The reported issue is now working properly.”
- “Cleaned and prepared the surface and applied the first coat of paint. Some work was completed, but additional work is still required.”

The browser preview is editable so staff can use the generated summary immediately or improve its wording. The server separately stores the profile ID, selected action codes, result code, deterministic baseline, final edited description, and optional remarks. Selecting **Other work performed** reveals a required plain-language field, and that wording is incorporated into the deterministic sentence. Raw codes never replace the human-readable history.

The staff-facing result choices are Working Properly, More Work Is Needed, Waiting for Materials, Problem Still Exists, and Further Inspection Is Needed. Their stable codes remain `RESOLVED`, `PARTIAL`, `WAITING_SUPPLIES`, `PROBLEM_PERSISTS`, and `INVESTIGATION` for APIs and reporting.

Work Order categories map to suitable profiles and all five staff execution actions use the same reusable Blade component and `GuidedDescriptionService`. Progress updates use the selected result sentence. Stop, blocker, individual-contribution, and whole-job actions reuse the selected action clauses with a contextual deterministic ending. For example, a material blocker can produce: “Inspected the affected area. Work cannot continue because a replacement electrical outlet is required. The task will resume once the material is available.” Routine templates can independently select a profile.

No LLM, network request, or external generation service is involved. Browser generation provides immediate feedback, while the server independently validates codes and rebuilds the original deterministic summary before storing it.

Profile data is language-ready because labels and clauses are data, while codes remain stable for APIs and future mobile synchronization. A future translation layer can localize labels and clauses without migrating historical codes.
