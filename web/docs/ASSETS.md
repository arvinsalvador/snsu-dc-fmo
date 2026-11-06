# Asset Registry

The Asset Registry holds individually identifiable and maintainable records. Buildings, floors, offices, rooms, and areas remain locations; a facility component is registered as an asset only when its individual inspection, preventive-maintenance, repair, condition, or lifecycle history has operational value.

Supported asset classes are Equipment, Facility Component, Utility/System, Safety Equipment, Vehicle, and Other. Examples include an air conditioner, roof drainage system, electrical panel, fire extinguisher, water pump, or vehicle.

Assets may have a parent asset. This models systems without creating separate domain tables: a Water Desalination System can contain feed pumps, filters, a membrane assembly, storage tank, and distribution pump. Components retain independent asset codes, QR codes, condition, lifecycle, inspections, PM plans, repairs, and photos. The parent presents aggregated history.

Asset detail separates Overview, Preventive Maintenance, Inspections, Repairs, History, and Photos. Physical condition and operational lifecycle are independent concepts.

## Vehicle assets

Vehicles (the University Van, the Revo, and similar) are ordinary assets with class **Vehicle**; there is no separate vehicle registry. Seeded types: Van, Utility Vehicle, SUV, Pickup, Motorcycle, Other Vehicle. Make and model use Brand and Model. Plate number, year, and passenger capacity are stored in the extensible `specifications` JSON column, and `current_odometer_km` / `odometer_updated_at` hold the odometer. The asset page shows a Vehicle Details card (including mileage-based maintenance status) and a Travel History table of completed trips. Only active vehicles that are not under repair, retired, or disposed can be assigned to trips. See `TRANSPORTATION.md` and `PREVENTIVE_MAINTENANCE.md`.
