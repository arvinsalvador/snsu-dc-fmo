# Locations

Locations are multi-campus: Campus → Building → optional Floor → Office, Room, or Area. A building may have locations directly, so future building-wide work can omit a subordinate location. `building_locations` uses a centralized type enum and supports Office, Classroom, Laboratory, Room, Lobby, Hallway, Comfort Room, Storage, Outdoor Area, Common Area, and Other.

Each record has its own active flag. A child remains historically intact when a parent is inactive, but effective availability requires the campus, building, optional floor, and location all to be active. Normal operational APIs can request `active=1` and exclude unavailable descendants.

Parents with child records cannot be hard deleted. Delete is only for unused records; deactivation is the routine retirement action. The initial `DC` campus is reference data only; the schema supports any number of campuses.

FMO Head and System Administrator manage locations. The FMO Head may delegate building and subordinate-location operational permissions through the existing direct-permission mechanism. Other approved roles receive location read access. APIs: `GET /api/v1/campuses`, `/buildings`, and `/locations`, with useful active and relationship filters.
