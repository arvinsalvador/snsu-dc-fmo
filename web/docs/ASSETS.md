# Asset Registry

The Asset Registry holds individually identifiable and maintainable records. Buildings, floors, offices, rooms, and areas remain locations; a facility component is registered as an asset only when its individual inspection, preventive-maintenance, repair, condition, or lifecycle history has operational value.

Supported asset classes are Equipment, Facility Component, Utility/System, Safety Equipment, Vehicle, and Other. Examples include an air conditioner, roof drainage system, electrical panel, fire extinguisher, water pump, or vehicle.

Assets may have a parent asset. This models systems without creating separate domain tables: a Water Desalination System can contain feed pumps, filters, a membrane assembly, storage tank, and distribution pump. Components retain independent asset codes, QR codes, condition, lifecycle, inspections, PM plans, repairs, and photos. The parent presents aggregated history.

Asset detail separates Overview, Preventive Maintenance, Inspections, Repairs, History, and Photos. Physical condition and operational lifecycle are independent concepts.
