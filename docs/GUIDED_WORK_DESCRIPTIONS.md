# Guided Work Descriptions

Guided work descriptions are deterministic sentence builders, not generative AI. A database-backed `GuidedDescriptionProfile` contains stable action codes, plain-language labels, deterministic clauses, outcome codes, and a version marker.

The seeded profiles are Electrical, Plumbing, Carpentry and Woodworking, Air-conditioning, General Maintenance, Cleaning Surroundings, Comfort Room Cleaning, Garbage Disposal, and Grass Cutting.

Staff select one or more actions and an outcome. `GuidedDescriptionService` validates those codes against the selected profile, preserves their order, joins the clauses, and appends the outcome sentence. For example:

- “Inspected the affected area, replaced the damaged electrical outlet, and tested the installation after repair. The task was completed successfully.”
- “Cleaned and disinfected the fixtures and mopped and dried the floor. The task was completed successfully.”
- “Collected waste from the assigned bins and segregated the collected waste. Further work is waiting for the required materials or supplies.”
- “Cut the grass in the assigned area, trimmed the edges and hard-to-reach sections, and collected and cleared the grass clippings. The task was completed successfully.”

The browser preview is editable so staff can improve the readable summary or use free text. The server still stores profile ID, selected action codes, outcome code, deterministic baseline, edited description, and optional remarks as applicable. Raw codes do not replace the human-readable history.

Work Order categories map to suitable profiles and the existing execution update form uses the reusable component. Existing free-text updates remain supported. Routine templates can independently select a profile.

Profile data is language-ready because labels and clauses are data, while codes remain stable for APIs and future mobile synchronization. A future translation layer can localize labels/clauses without migrating historical codes.
