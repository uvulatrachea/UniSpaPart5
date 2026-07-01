import { Head, router, useForm, usePage } from "@inertiajs/react";
import { useEffect, useMemo, useRef, useState } from "react";
import AdminShell from "./Partials/AdminShell";

export default function ManageServices({
  kpis = {},
  categories = { data: [], links: [] },
  services = { data: [], links: [] },
  promotions = { data: [], links: [] },
  allServices = [],
  filters = {},
}) {
  const { flash = {} } = usePage().props;

  const [section, setSection] = useState(filters.section || "services");
  const [query, setQuery] = useState(filters.search || "");
  const [sort, setSort] = useState(filters.sort || "created_at");
  const [direction, setDirection] = useState(filters.direction || "desc");
  const [perPage, setPerPage] = useState(String(filters.per_page || 8));

  const [serviceModal, setServiceModal] = useState({ open: false, mode: "create", item: null });
  const [promotionModal, setPromotionModal] = useState({ open: false, mode: "create", item: null });
  const [categoryModal, setCategoryModal] = useState({ open: false, mode: "create", item: null });
  const [toast, setToast] = useState({ show: false, type: "success", message: "" });
  const [confirmDialog, setConfirmDialog] = useState({ open: false, title: "", message: "", onConfirm: null });

  const params = useMemo(
    () => ({ section, search: query, sort, direction, per_page: perPage }),
    [section, query, sort, direction, perPage]
  );

  const applyFilters = () => router.get(route("admin.services"), params, { preserveState: true, replace: true });
  const changeSection = (nextSection) => {
    setSection(nextSection);
    router.get(route("admin.services"), { ...params, section: nextSection }, { preserveState: true, replace: true });
  };
  const onPaginate = (url) => url && router.visit(url, { preserveState: true, preserveScroll: true });

  useEffect(() => {
    if (flash.success) setToast({ show: true, type: "success", message: flash.success });
    if (flash.error) setToast({ show: true, type: "error", message: flash.error });
  }, [flash.success, flash.error]);

  useEffect(() => {
    if (!toast.show) return;
    const timer = setTimeout(() => setToast((prev) => ({ ...prev, show: false })), 2800);
    return () => clearTimeout(timer);
  }, [toast.show]);

  const openCreateModalForSection = () => {
    if (section === "categories") {
      setCategoryModal({ open: true, mode: "create", item: null });
      return;
    }
    if (section === "promotions") {
      setPromotionModal({ open: true, mode: "create", item: null });
      return;
    }
    setServiceModal({ open: true, mode: "create", item: null });
  };

  const currentAddLabel = section === "categories"
    ? "+ Add Category"
    : section === "promotions"
      ? "+ Add Promotion"
      : "+ Add Service";

  const askDelete = (title, message, onConfirm) => {
    setConfirmDialog({ open: true, title, message, onConfirm });
  };

  const closeDeleteDialog = () => setConfirmDialog({ open: false, title: "", message: "", onConfirm: null });

  const removeCategory = (id, name) => {
    askDelete("Delete Category", `Are you sure you want to delete category “${name}”?`, () => {
      router.delete(route("admin.services.categories.destroy", id), { preserveScroll: true });
      closeDeleteDialog();
    });
  };

  const removeService = (id, name) => {
    askDelete("Delete Service", `Are you sure you want to delete service “${name}”?`, () => {
      router.delete(route("admin.services.items.destroy", id), { preserveScroll: true });
      closeDeleteDialog();
    });
  };

  const removePromotion = (id, title) => {
    askDelete("Delete Promotion", `Are you sure you want to delete promotion “${title}”?`, () => {
      router.delete(route("admin.services.promotions.destroy", id), { preserveScroll: true });
      closeDeleteDialog();
    });
  };

  return (
    <>
      <Head title="Manage Services" />
      <AdminShell title="Manage Services" subtitle="Manage categories, services and promotions with full CRUD + image upload.">
        {(flash.success || flash.error) && (
          <div className={`mb-4 rounded-xl border px-4 py-3 text-sm font-semibold ${flash.success ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-rose-200 bg-rose-50 text-rose-700"}`}>
            {flash.success || flash.error}
          </div>
        )}

        <section className="grid grid-cols-2 gap-3 lg:grid-cols-6">
          <Kpi label="Services" value={kpis.totalServices} />
          <Kpi label="Categories" value={kpis.totalCategories} />
          <Kpi label="Popular Services" value={kpis.popularServices} />
          <Kpi label="Promotions" value={kpis.totalPromotions} />
          <Kpi label="Permanent Promo" value={kpis.permanentPromotions} />
          <Kpi label="Seasonal Promo" value={kpis.seasonalPromotions} />
        </section>

        <section className="mt-5 rounded-2xl border border-slate-200 bg-white p-3 shadow-sm sm:p-4">
          <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <div className="flex flex-1 flex-wrap gap-2">
              <select value={section} onChange={(e) => changeSection(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold">
                <option value="services">Service Items</option>
                <option value="categories">Service Categories</option>
                <option value="promotions">Promotions</option>
              </select>
              <input value={query} onChange={(e) => setQuery(e.target.value)} onKeyDown={(e) => e.key === "Enter" && applyFilters()} className="min-w-[180px] flex-1 rounded-lg border border-slate-200 px-3 py-2 text-sm" placeholder="Search by ID/name/title..." />
              <select value={sort} onChange={(e) => setSort(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <option value="created_at">Created Date</option>
                <option value="name">Name</option>
                <option value={section === "promotions" ? "promotion_id" : "id"}>ID</option>
                {section === "promotions" && <option value="end_date">Last Day</option>}
                {section === "services" && <option value="price">Price</option>}
              </select>
              <select value={direction} onChange={(e) => setDirection(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                <option value="desc">Desc</option>
                <option value="asc">Asc</option>
              </select>
              <select value={perPage} onChange={(e) => setPerPage(e.target.value)} className="rounded-lg border border-slate-200 px-3 py-2 text-sm">
                {[5, 8, 10, 15, 20].map((v) => <option key={v} value={v}>{v} / page</option>)}
              </select>
              <button onClick={applyFilters} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">Apply</button>
            </div>

            <div className="flex flex-wrap gap-2">
              <button onClick={openCreateModalForSection} className="rounded-lg bg-slate-900 px-4 py-2 text-sm font-bold text-white">{currentAddLabel}</button>
              <button onClick={() => changeSection("services")} className="rounded-lg border border-indigo-300 bg-indigo-50 px-4 py-2 text-sm font-bold text-indigo-700">Service Items</button>
              <button onClick={() => changeSection("categories")} className="rounded-lg border border-violet-300 bg-violet-50 px-4 py-2 text-sm font-bold text-violet-700">Service Categories</button>
              <button onClick={() => changeSection("promotions")} className="rounded-lg border border-fuchsia-300 bg-fuchsia-50 px-4 py-2 text-sm font-bold text-fuchsia-700">Promotions</button>
            </div>
          </div>

          {section === "categories" && (
            <div className="mt-4">
              <Table
                title="Categories"
                columns={["Category", "Gender", "Sort", "Status", "Services", "Image", "Action"]}
                rows={categories.data || []}
                links={categories.links || []}
                onPaginate={onPaginate}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><Cell label={`#${row.id} ${row.name}`} subtitle={row.description} /></td>
                    <td className="px-3 py-3">{humanize(row.gender)}</td>
                    <td className="px-3 py-3">{row.sort_order ?? 0}</td>
                    <td className="px-3 py-3"><Badge type={row.is_active ? "green" : "amber"}>{row.is_active ? "Active" : "Inactive"}</Badge></td>
                    <td className="px-3 py-3">{Number(row.service_count || 0)}</td>
                    <td className="px-3 py-3"><Thumb src={row.image_url} /></td>
                    <td className="px-3 py-3"><Actions onEdit={() => setCategoryModal({ open: true, mode: "edit", item: row })} onDelete={() => removeCategory(row.id, row.name)} /></td>
                  </>
                )}
              />
            </div>
          )}

          {section === "services" && (
            <div className="mt-4">
              <Table
                title="Service Items"
                columns={["Service", "Category", "Price", "Duration", "Popular", "Promotions", "Image", "Action"]}
                rows={services.data || []}
                links={services.links || []}
                onPaginate={onPaginate}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><Cell label={`#${row.id} ${row.name}`} subtitle={row.description} /></td>
                    <td className="px-3 py-3">{row.category_name || "-"}</td>
                    <td className="px-3 py-3">RM {Number(row.price || 0).toFixed(2)}</td>
                    <td className="px-3 py-3">{row.duration_minutes} min</td>
                    <td className="px-3 py-3"><Badge type={row.is_popular ? "blue" : "amber"}>{row.is_popular ? "Popular" : "Normal"}</Badge></td>
                    <td className="px-3 py-3">{Number(row.promotion_count || 0)}</td>
                    <td className="px-3 py-3"><Thumb src={row.image_url} /></td>
                    <td className="px-3 py-3">
                      <Actions
                        onEdit={() => setServiceModal({ open: true, mode: "edit", item: row })}
                        onDelete={() => removeService(row.id, row.name)}
                        extraButtons={[
                          {
                            label: "Promotions",
                            onClick: () => {
                              changeSection("promotions");
                            },
                            className: "border-fuchsia-300 text-fuchsia-700",
                          },
                        ]}
                      />
                    </td>
                  </>
                )}
              />
            </div>
          )}

          {section === "promotions" && (
            <div className="mt-4">
              <Table
                title="Promotions"
                columns={["Promotion", "Code", "Type", "Discount", "Last Day", "Status", "Header", "Services", "Banner", "Action"]}
                rows={promotions.data || []}
                links={promotions.links || []}
                onPaginate={onPaginate}
                renderRow={(row) => (
                  <>
                    <td className="px-3 py-3"><Cell label={`#${row.promotion_id} ${row.title}`} subtitle={row.description} /></td>
                    <td className="px-3 py-3">{row.promo_code ? <span className="rounded-md bg-violet-100 px-2 py-1 text-xs font-bold text-violet-700">{row.promo_code}</span> : <span className="text-xs text-slate-400">—</span>}</td>
                    <td className="px-3 py-3"><Badge type={row.promotion_type === "permanent" ? "blue" : "amber"}>{humanize(row.promotion_type)}</Badge></td>
                    <td className="px-3 py-3">{row.discount_type === "fixed" ? `RM ${Number(row.discount_value || 0).toFixed(2)}` : `${Number(row.discount_value || 0)}%`}</td>
                    <td className="px-3 py-3">{row.end_date ? formatDate(row.end_date) : "No End Date"}</td>
                    <td className="px-3 py-3">
                      <button onClick={() => togglePromotion(row)} className="rounded-full border border-slate-300 px-3 py-1 text-xs font-bold">
                        {row.is_active ? "Active" : "Inactive"}
                      </button>
                    </td>
                    <td className="px-3 py-3">
                      <button onClick={() => togglePromotionHeader(row)} className={`rounded-full border px-3 py-1 text-xs font-bold ${row.show_in_dashboard_header ? "border-emerald-300 text-emerald-700" : "border-slate-300 text-slate-600"}`}>
                        {row.show_in_dashboard_header ? "Shown" : "Hidden"}
                      </button>
                    </td>
                    <td className="px-3 py-3">{Number(row.service_count || 0)}</td>
                    <td className="px-3 py-3"><Thumb src={row.banner_image} /></td>
                    <td className="px-3 py-3">
                      <Actions
                        onEdit={() => setPromotionModal({ open: true, mode: "edit", item: row })}
                        onDelete={() => removePromotion(row.promotion_id, row.title)}
                        extraButtons={[
                          {
                            label: "Print",
                            onClick: () => window.open(route("admin.services.promotions.print", row.promotion_id), "_blank"),
                            className: "border-sky-300 text-sky-700",
                          },
                        ]}
                      />
                    </td>
                  </>
                )}
              />
            </div>
          )}
        </section>

        {categoryModal.open && <CategoryModal modal={categoryModal} onClose={() => setCategoryModal({ open: false, mode: "create", item: null })} />}
        {serviceModal.open && <ServiceModal modal={serviceModal} onClose={() => setServiceModal({ open: false, mode: "create", item: null })} categories={categories.data || []} />}
        {promotionModal.open && <PromotionModal modal={promotionModal} onClose={() => setPromotionModal({ open: false, mode: "create", item: null })} allServices={allServices} />}
        {toast.show && <Toast type={toast.type} message={toast.message} onClose={() => setToast((prev) => ({ ...prev, show: false }))} />}
        {confirmDialog.open && (
          <ConfirmDialog
            title={confirmDialog.title}
            message={confirmDialog.message}
            onCancel={closeDeleteDialog}
            onConfirm={confirmDialog.onConfirm}
          />
        )}
      </AdminShell>
    </>
  );
}

function togglePromotion(item) {
  router.patch(route("admin.services.promotions.status", item.promotion_id), { is_active: !item.is_active }, { preserveScroll: true });
}

function togglePromotionHeader(item) {
  router.patch(
    route("admin.services.promotions.dashboard_header", item.promotion_id),
    { show_in_dashboard_header: !item.show_in_dashboard_header },
    { preserveScroll: true }
  );
}

function CategoryModal({ modal, onClose }) {
  const { data, setData, post, patch, processing, errors, setError, clearErrors } = useForm({
    name: modal.item?.name || "",
    gender: modal.item?.gender || "all",
    description: modal.item?.description || "",
    icon: modal.item?.icon || "",
    sort_order: modal.item?.sort_order ?? 0,
    is_active: !!modal.item?.is_active,
    image_file: null,
  });

  const submit = (e) => {
    e.preventDefault();
    clearErrors();
    if (!String(data.name || "").trim()) {
      setError("name", "Category name is required.");
      return;
    }
    if (modal.mode === "create") post(route("admin.services.categories.store"), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    else patch(route("admin.services.categories.update", modal.item.id), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Modal title={`${modal.mode === "create" ? "Add" : "Edit"} Category`} onClose={onClose} onSubmit={submit} processing={processing}>
      <Field label="Name" value={data.name} onChange={(v) => setData("name", v)} error={errors.name} />
      <SelectField label="Gender" value={data.gender} onChange={(v) => setData("gender", v)} options={["all", "female", "male"]} />
      <Field label="Sort Order" type="number" value={data.sort_order} onChange={(v) => setData("sort_order", Number(v || 0))} error={errors.sort_order} />
      <Field label="Icon" value={data.icon} onChange={(v) => setData("icon", v)} error={errors.icon} />
      <TextArea label="Description" value={data.description} onChange={(v) => setData("description", v)} error={errors.description} />
      <FileField label="Category Image" onChange={(f) => setData("image_file", f)} error={errors.image_file} />
      <Checkbox label="Active" checked={!!data.is_active} onChange={(v) => setData("is_active", v)} />
    </Modal>
  );
}

function ServiceModal({ modal, onClose, categories = [] }) {
  const { data, setData, post, patch, processing, errors, setError, clearErrors } = useForm({
    category_id: modal.item?.category_id || categories?.[0]?.id || "",
    name: modal.item?.name || "",
    description: modal.item?.description || "",
    price: modal.item?.price || 0,
    duration_minutes: modal.item?.duration_minutes || 60,
    is_popular: !!modal.item?.is_popular,
    tags_text: (modal.item?.tags || "").replace(/[\[\]"]/g, "").replace(/,/g, ", "),
    image_file: null,
  });

  const submit = (e) => {
    e.preventDefault();
    clearErrors();
    if (!String(data.category_id || "").trim()) {
      setError("category_id", "Please select a category.");
      return;
    }
    if (!String(data.name || "").trim()) {
      setError("name", "Service name is required.");
      return;
    }
    if (Number(data.price || 0) <= 0) {
      setError("price", "Price must be more than 0.");
      return;
    }
    if (Number(data.duration_minutes || 0) <= 0) {
      setError("duration_minutes", "Duration must be more than 0.");
      return;
    }
    if (modal.mode === "create") post(route("admin.services.items.store"), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    else patch(route("admin.services.items.update", modal.item.id), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
  };

  return (
    <Modal title={`${modal.mode === "create" ? "Add" : "Edit"} Service`} onClose={onClose} onSubmit={submit} processing={processing}>
      <label className="text-sm">
        <span className="mb-1 block font-medium text-slate-700">Category</span>
        <select value={data.category_id} onChange={(e) => setData("category_id", e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 transition-all duration-150 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200">
          {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
        </select>
        {errors.category_id && <span className="text-xs text-rose-600">{errors.category_id}</span>}
      </label>
      <Field label="Service Name" value={data.name} onChange={(v) => setData("name", v)} error={errors.name} />
      <TextArea label="Description" value={data.description} onChange={(v) => setData("description", v)} error={errors.description} />
      <Field label="Price" type="number" value={data.price} onChange={(v) => setData("price", v)} error={errors.price} />
      <Field label="Duration (minutes)" type="number" value={data.duration_minutes} onChange={(v) => setData("duration_minutes", v)} error={errors.duration_minutes} />
      <Field label="Tags (comma-separated)" value={data.tags_text} onChange={(v) => setData("tags_text", v)} error={errors.tags_text} />
      <FileField label="Service Image" onChange={(f) => setData("image_file", f)} error={errors.image_file} />
      <Checkbox label="Mark as Popular" checked={!!data.is_popular} onChange={(v) => setData("is_popular", v)} />
    </Modal>
  );
}

function PromotionModal({ modal, onClose, allServices = [] }) {
  const [step, setStep] = useState(1);
  const [servicePage, setServicePage] = useState(1);
  const servicePerPage = 8;
  const initialServiceIds = useMemo(() => {
    if (!modal.item) return [];
    return parseLinkedServiceIds(modal.item.linked_service_ids);
  }, [modal.item]);

  const { data, setData, post, patch, processing, errors, setError, clearErrors } = useForm({
    title: modal.item?.title || "",
    description: modal.item?.description || "",
    discount_type: modal.item?.discount_type || "percentage",
    discount_value: modal.item?.discount_value || 10,
    promotion_type: modal.item?.promotion_type || (modal.item?.end_date ? "seasonal" : "permanent"),
    start_date: modal.item?.start_date ? String(modal.item.start_date).slice(0, 10) : "",
    end_date: modal.item?.end_date ? String(modal.item.end_date).slice(0, 10) : "",
    is_active: !!modal.item?.is_active,
    show_in_dashboard_header: modal.item?.show_in_dashboard_header ?? true,
    link: modal.item?.link || "",
    promo_code: modal.item?.promo_code || "",
    service_ids: initialServiceIds,
    banner_file: null,
  });

  const toggleService = (serviceId) => {
    const id = Number(serviceId);
    const exists = data.service_ids.includes(id);
    if (exists) setData("service_ids", data.service_ids.filter((x) => x !== id));
    else setData("service_ids", [...data.service_ids, id]);
  };

  const submit = (e) => {
    e.preventDefault();
    clearErrors();
    if (!String(data.title || "").trim()) {
      setError("title", "Promotion title is required.");
      return;
    }
    if (Number(data.discount_value || 0) <= 0) {
      setError("discount_value", "Discount value must be more than 0.");
      return;
    }
    if (data.promotion_type === "seasonal" && !String(data.end_date || "").trim()) {
      setError("end_date", "End date is required for seasonal promotions.");
      return;
    }
    if (modal.mode === "create") post(route("admin.services.promotions.store"), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
    else patch(route("admin.services.promotions.update", modal.item.promotion_id), { forceFormData: true, preserveScroll: true, onSuccess: onClose });
  };

  const totalServicePages = Math.max(1, Math.ceil((allServices?.length || 0) / servicePerPage));
  const pagedServices = (allServices || []).slice((servicePage - 1) * servicePerPage, servicePage * servicePerPage);

  return (
    <Modal title={`${modal.mode === "create" ? "Add" : "Edit"} Promotion`} onClose={onClose} onSubmit={submit} processing={processing} compact>
      <div className="mb-2 sm:col-span-2">
        <div className="flex flex-wrap gap-2">
          <button type="button" onClick={() => setStep(1)} className={`rounded-md px-3 py-1 text-xs font-bold ${step === 1 ? "bg-violet-600 text-white" : "border border-slate-300 text-slate-600"}`}>Page 1: Details</button>
          <button type="button" onClick={() => setStep(2)} className={`rounded-md px-3 py-1 text-xs font-bold ${step === 2 ? "bg-violet-600 text-white" : "border border-slate-300 text-slate-600"}`}>Page 2: Dates</button>
          <button type="button" onClick={() => setStep(3)} className={`rounded-md px-3 py-1 text-xs font-bold ${step === 3 ? "bg-violet-600 text-white" : "border border-slate-300 text-slate-600"}`}>Page 3: Services</button>
        </div>
      </div>

      {step === 1 && (
        <>
          <Field label="Title" value={data.title} onChange={(v) => setData("title", v)} error={errors.title} />
          <TextArea label="Description" value={data.description} onChange={(v) => setData("description", v)} error={errors.description} />
          <SelectField label="Discount Type" value={data.discount_type} onChange={(v) => setData("discount_type", v)} options={["percentage", "fixed"]} />
          <Field label={data.discount_type === "fixed" ? "Discount Amount (RM)" : "Discount (%)"} type="number" value={data.discount_value} onChange={(v) => setData("discount_value", v)} error={errors.discount_value} />
          <Field label="Link (optional)" value={data.link} onChange={(v) => setData("link", v)} error={errors.link} />
          <Field label="Promo Code (optional, e.g. STUDENT10)" value={data.promo_code} onChange={(v) => setData("promo_code", String(v).toUpperCase())} error={errors.promo_code} />
          <FileField label="Promotion Banner" onChange={(f) => setData("banner_file", f)} error={errors.banner_file} />
        </>
      )}

      {step === 2 && (
        <>
          <SelectField label="Promotion Type" value={data.promotion_type} onChange={(v) => setData("promotion_type", v)} options={["permanent", "seasonal"]} />
          <Field label="Start Date" type="date" value={data.start_date} onChange={(v) => setData("start_date", v)} error={errors.start_date} />
          {data.promotion_type === "seasonal" && <Field label="Last Day (End Date)" type="date" value={data.end_date} onChange={(v) => setData("end_date", v)} error={errors.end_date} />}
          <Checkbox label="Active" checked={!!data.is_active} onChange={(v) => setData("is_active", v)} />
          <Checkbox label="Show on customer dashboard header" checked={!!data.show_in_dashboard_header} onChange={(v) => setData("show_in_dashboard_header", v)} />
        </>
      )}

      {step === 3 && (
        <div className="rounded-lg border border-slate-200 p-3 text-sm sm:col-span-2">
          <p className="mb-2 font-bold text-slate-700">Apply to Services</p>
          <div className="grid max-h-40 grid-cols-1 gap-1 overflow-y-auto sm:grid-cols-2">
            {pagedServices.map((s) => (
              <label key={s.id} className="flex items-center gap-2 rounded px-2 py-1 hover:bg-slate-50">
                <input type="checkbox" checked={data.service_ids.includes(Number(s.id))} onChange={() => toggleService(s.id)} />
                <span>#{s.id} {s.name}</span>
              </label>
            ))}
          </div>
          <div className="mt-3 flex items-center justify-between">
            <button type="button" disabled={servicePage <= 1} onClick={() => setServicePage((p) => Math.max(1, p - 1))} className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50">Previous</button>
            <span className="text-xs text-slate-500">Page {servicePage} / {totalServicePages}</span>
            <button type="button" disabled={servicePage >= totalServicePages} onClick={() => setServicePage((p) => Math.min(totalServicePages, p + 1))} className="rounded border border-slate-300 px-2 py-1 text-xs disabled:opacity-50">Next</button>
          </div>
        </div>
      )}

      <div className="mt-1 flex justify-end gap-2 sm:col-span-2">
        {step > 1 && <button type="button" onClick={() => setStep((s) => s - 1)} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Back</button>}
        {step < 3 ? (
          <button type="button" onClick={() => setStep((s) => s + 1)} className="rounded-lg bg-slate-800 px-3 py-2 text-sm font-bold text-white">Next Page</button>
        ) : null}
      </div>
    </Modal>
  );
}

function Modal({ title, children, onClose, onSubmit, processing, compact = false }) {
  return (
    <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4">
      <form onSubmit={onSubmit} className={`max-h-[90vh] w-full overflow-y-auto rounded-2xl bg-white p-5 shadow-2xl ${compact ? "max-w-xl" : "max-w-2xl"}`}>
        <div className="sticky top-0 z-10 mb-3 flex items-start justify-between gap-2 bg-white pb-2">
          <h3 className="text-lg font-extrabold text-slate-800">{title}</h3>
          <button type="button" onClick={onClose} className="rounded-md border border-slate-200 px-3 py-1 text-xs font-bold text-slate-600 hover:bg-slate-50">✕ Close</button>
        </div>
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-2">{children}</div>
        <div className="sticky bottom-0 mt-5 flex justify-end gap-2 bg-white pt-2">
          <button type="button" onClick={onClose} className="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold">Close</button>
          <button disabled={processing} className="rounded-lg bg-violet-600 px-4 py-2 text-sm font-bold text-white disabled:opacity-50">Save</button>
        </div>
      </form>
    </div>
  );
}

function Toast({ type = "success", message, onClose }) {
  return (
    <div className={`fixed right-4 top-4 z-[60] min-w-[280px] max-w-[420px] rounded-xl border px-4 py-3 text-sm font-semibold shadow-lg ${type === "success" ? "border-emerald-200 bg-emerald-50 text-emerald-700" : "border-rose-200 bg-rose-50 text-rose-700"}`}>
      <div className="flex items-start justify-between gap-3">
        <p>{message}</p>
        <button type="button" className="text-xs opacity-80" onClick={onClose}>✕</button>
      </div>
    </div>
  );
}

function ConfirmDialog({ title, message, onCancel, onConfirm }) {
  return (
    <div className="fixed inset-0 z-[70] flex items-center justify-center bg-black/45 p-4">
      <div className="w-full max-w-md rounded-2xl bg-white p-5 shadow-2xl">
        <h4 className="text-base font-extrabold text-slate-800">{title}</h4>
        <p className="mt-2 text-sm text-slate-600">{message}</p>
        <div className="mt-4 flex justify-end gap-2">
          <button type="button" onClick={onCancel} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Cancel</button>
          <button type="button" onClick={onConfirm} className="rounded-lg bg-rose-600 px-3 py-2 text-sm font-bold text-white">Confirm Delete</button>
        </div>
      </div>
    </div>
  );
}

function Kpi({ label, value }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-3 shadow-sm">
      <p className="text-xs font-bold uppercase tracking-wide text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-extrabold text-slate-800">{Number(value || 0)}</p>
    </div>
  );
}

function Table({ title, columns, rows, links, onPaginate, renderRow }) {
  return (
    <div className="rounded-xl border border-slate-200">
      <div className="border-b border-slate-200 px-4 py-3 text-sm font-bold text-slate-700">{title}</div>
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="bg-slate-50 text-left text-slate-500"><tr>{columns.map((c) => <th key={c} className="px-3 py-2 font-semibold">{c}</th>)}</tr></thead>
          <tbody>{rows.map((row) => <tr key={row.id || row.promotion_id} className="border-t border-slate-100">{renderRow(row)}</tr>)}</tbody>
        </table>
      </div>
      {!!links?.length && (
        <div className="flex flex-wrap gap-1 border-t border-slate-200 p-3">
          {links.map((l, i) => (
            <button key={`${l.label}-${i}`} disabled={!l.url} onClick={() => onPaginate(l.url)} className={`rounded px-2 py-1 text-xs ${l.active ? "bg-slate-900 text-white" : "border border-slate-200"} disabled:opacity-40`} dangerouslySetInnerHTML={{ __html: l.label }} />
          ))}
        </div>
      )}
    </div>
  );
}

function Actions({ onEdit, onDelete, extraButtons = [] }) {
  return (
    <div className="flex flex-wrap gap-2">
      <button onClick={onEdit} type="button" className="rounded border border-slate-300 px-2 py-1 text-xs font-semibold">Edit</button>
      {extraButtons.map((btn) => (
        <button
          key={btn.label}
          onClick={btn.onClick}
          type="button"
          className={`rounded border px-2 py-1 text-xs font-semibold ${btn.className || "border-slate-300"}`}
        >
          {btn.label}
        </button>
      ))}
      <button onClick={onDelete} type="button" className="rounded border border-rose-300 px-2 py-1 text-xs font-semibold text-rose-700">Delete</button>
    </div>
  );
}

function Cell({ label, subtitle }) {
  return <div><p className="font-semibold text-slate-700">{label || "-"}</p><p className="text-xs text-slate-500">{subtitle || "-"}</p></div>;
}

function Thumb({ src }) {
  return src ? <img src={src} alt="" className="h-10 w-16 rounded-lg border border-slate-200 object-cover" /> : <span className="text-xs text-slate-400">No image</span>;
}

function Field({ label, value, onChange, error, type = "text" }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <input type={type} value={value ?? ""} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 transition-all duration-150 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200" />
      {error && <span className="text-xs text-rose-600">{error}</span>}
    </label>
  );
}

function TextArea({ label, value, onChange, error }) {
  return (
    <label className="text-sm sm:col-span-2">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <textarea value={value ?? ""} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 transition-all duration-150 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200" rows={3} />
      {error && <span className="text-xs text-rose-600">{error}</span>}
    </label>
  );
}

function SelectField({ label, value, onChange, options }) {
  return (
    <label className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <select value={value} onChange={(e) => onChange(e.target.value)} className="w-full rounded-lg border border-slate-200 px-3 py-2 transition-all duration-150 focus:border-violet-400 focus:outline-none focus:ring-2 focus:ring-violet-200">
        {options.map((opt) => <option key={opt} value={opt}>{humanize(opt)}</option>)}
      </select>
    </label>
  );
}

function FileField({ label, onChange, error }) {
  const ref = useRef(null);
  return (
    <div className="text-sm">
      <span className="mb-1 block font-medium text-slate-700">{label}</span>
      <button type="button" onClick={() => ref.current?.click()} className="rounded-lg border border-slate-300 px-3 py-2 text-sm font-semibold">Choose Image</button>
      <input ref={ref} type="file" accept="image/*" className="hidden" onChange={(e) => onChange(e.target.files?.[0] || null)} />
      {error && <span className="block text-xs text-rose-600">{error}</span>}
    </div>
  );
}

function Checkbox({ label, checked, onChange }) {
  return (
    <label className="flex items-center gap-2 rounded-lg border border-slate-200 px-3 py-2 text-sm font-medium">
      <input type="checkbox" checked={!!checked} onChange={(e) => onChange(e.target.checked)} />
      <span>{label}</span>
    </label>
  );
}

function Badge({ type = "green", children }) {
  const styles = { green: "bg-emerald-100 text-emerald-700", amber: "bg-amber-100 text-amber-700", rose: "bg-rose-100 text-rose-700", blue: "bg-blue-100 text-blue-700" };
  return <span className={`rounded-full px-3 py-1 text-xs font-bold ${styles[type]}`}>{children}</span>;
}

function humanize(value) {
  return String(value || "-").replace(/[_-]+/g, " ").replace(/\b\w/g, (c) => c.toUpperCase());
}

function formatDate(v) {
  if (!v) return "-";
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return String(v);
  return d.toLocaleDateString("en-MY", { day: "2-digit", month: "long", year: "numeric" });
}

function parseLinkedServiceIds(value) {
  if (Array.isArray(value)) {
    return value.map((v) => Number(v)).filter((v) => Number.isFinite(v));
  }

  if (typeof value === "string") {
    const trimmed = value.trim();
    if (!trimmed) return [];

    if (trimmed.startsWith("{") && trimmed.endsWith("}")) {
      return trimmed
        .slice(1, -1)
        .split(",")
        .map((v) => Number(v.trim()))
        .filter((v) => Number.isFinite(v));
    }

    try {
      const parsed = JSON.parse(trimmed);
      if (Array.isArray(parsed)) {
        return parsed.map((v) => Number(v)).filter((v) => Number.isFinite(v));
      }
    } catch { }
  }

  return [];
}
