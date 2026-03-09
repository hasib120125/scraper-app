import AppLayout from "@/Layouts/AppLayout";
import { useForm } from "@inertiajs/react";
import { useState } from "react";

const PLATFORMS = [
    { value: "", label: "Auto-detect" },
    { value: "shopify", label: "Shopify" },
    { value: "woocommerce", label: "WooCommerce" },
    { value: "magento", label: "Magento" },
    { value: "generic", label: "Generic / Other" },
];

const PLATFORM_COLORS = {
    shopify: {
        bg: "rgba(149,191,71,0.1)",
        border: "rgba(149,191,71,0.25)",
        text: "#95bf47",
    },
    woocommerce: {
        bg: "rgba(150,88,138,0.1)",
        border: "rgba(150,88,138,0.25)",
        text: "#96588a",
    },
    magento: {
        bg: "rgba(235,138,9,0.1)",
        border: "rgba(235,138,9,0.25)",
        text: "#eb8a09",
    },
    generic: {
        bg: "rgba(107,107,128,0.1)",
        border: "rgba(107,107,128,0.25)",
        text: "#6b6b80",
    },
};

function PlatformBadge({ platform }) {
    if (!platform)
        return <span style={{ color: "#3d3d52", fontSize: 11 }}>auto</span>;
    const c = PLATFORM_COLORS[platform] || PLATFORM_COLORS.generic;
    return (
        <span
            style={{
                padding: "2px 8px",
                borderRadius: 4,
                fontSize: 11,
                fontWeight: 600,
                background: c.bg,
                border: `1px solid ${c.border}`,
                color: c.text,
                textTransform: "capitalize",
            }}
        >
            {platform}
        </span>
    );
}

function Modal({ title, onClose, children }) {
    return (
        <div
            style={{
                position: "fixed",
                inset: 0,
                zIndex: 100,
                background: "rgba(0,0,0,0.7)",
                backdropFilter: "blur(4px)",
                display: "flex",
                alignItems: "center",
                justifyContent: "center",
            }}
            onClick={onClose}
        >
            <div
                style={{
                    background: "#131320",
                    border: "1px solid #2a2a3a",
                    borderRadius: 16,
                    padding: 32,
                    width: "100%",
                    maxWidth: 480,
                }}
                onClick={(e) => e.stopPropagation()}
            >
                <div
                    style={{
                        display: "flex",
                        justifyContent: "space-between",
                        alignItems: "center",
                        marginBottom: 24,
                    }}
                >
                    <h2
                        style={{ color: "#fff", fontSize: 16, fontWeight: 700 }}
                    >
                        {title}
                    </h2>
                    <button
                        onClick={onClose}
                        style={{
                            background: "none",
                            border: "none",
                            color: "#6b6b80",
                            cursor: "pointer",
                            fontSize: 18,
                        }}
                    >
                        ✕
                    </button>
                </div>
                {children}
            </div>
        </div>
    );
}

function WebsiteForm({
    initial = {},
    onSubmit,
    processing,
    errors,
    submitLabel = "Save",
}) {
    const s = {
        label: {
            display: "block",
            fontSize: 12,
            color: "#6b6b80",
            marginBottom: 6,
            letterSpacing: "0.05em",
            textTransform: "uppercase",
        },
        input: {
            width: "100%",
            padding: "10px 14px",
            borderRadius: 8,
            background: "#0c0c14",
            border: "1px solid #2a2a3a",
            color: "#e8e8f0",
            fontSize: 13,
            outline: "none",
            boxSizing: "border-box",
        },
        group: { marginBottom: 18 },
        error: { color: "#ff6b6b", fontSize: 11, marginTop: 4 },
    };

    const [form, setForm] = useState({
        name: initial.name || "",
        url: initial.url || "",
        platform: initial.platform || "",
        notes: initial.notes || "",
        is_active: initial.is_active !== false,
    });

    const handle = (k, v) => setForm((p) => ({ ...p, [k]: v }));

    return (
        <form
            onSubmit={(e) => {
                e.preventDefault();
                onSubmit(form);
            }}
        >
            <div style={s.group}>
                <label style={s.label}>Site Name *</label>
                <input
                    style={s.input}
                    value={form.name}
                    onChange={(e) => handle("name", e.target.value)}
                    placeholder="e.g. Spandex House"
                    required
                />
                {errors.name && <div style={s.error}>{errors.name}</div>}
            </div>
            <div style={s.group}>
                <label style={s.label}>URL *</label>
                <input
                    style={s.input}
                    value={form.url}
                    onChange={(e) => handle("url", e.target.value)}
                    placeholder="https://www.example.com"
                    type="url"
                    required
                />
                {errors.url && <div style={s.error}>{errors.url}</div>}
            </div>
            <div style={s.group}>
                <label style={s.label}>Platform</label>
                <select
                    style={s.input}
                    value={form.platform}
                    onChange={(e) => handle("platform", e.target.value)}
                >
                    {PLATFORMS.map((p) => (
                        <option key={p.value} value={p.value}>
                            {p.label}
                        </option>
                    ))}
                </select>
            </div>
            <div style={s.group}>
                <label style={s.label}>Notes</label>
                <textarea
                    style={{ ...s.input, resize: "vertical", minHeight: 72 }}
                    value={form.notes}
                    onChange={(e) => handle("notes", e.target.value)}
                    placeholder="Optional notes..."
                />
            </div>
            <div
                style={{
                    display: "flex",
                    gap: 10,
                    justifyContent: "flex-end",
                    marginTop: 8,
                }}
            >
                <button
                    type="submit"
                    disabled={processing}
                    style={{
                        padding: "10px 24px",
                        borderRadius: 8,
                        border: "none",
                        cursor: "pointer",
                        background: processing
                            ? "#1a2e26"
                            : "linear-gradient(135deg,#00e5a0,#00b87a)",
                        color: processing ? "#00e5a0" : "#000",
                        fontWeight: 700,
                        fontSize: 13,
                    }}
                >
                    {processing ? "Saving..." : submitLabel}
                </button>
            </div>
        </form>
    );
}

export default function WebsitesIndex({ websites }) {
    const [showAdd, setShowAdd] = useState(false);
    const [editing, setEditing] = useState(null);
    const [deleting, setDeleting] = useState(null);

    const addForm = useForm({
        name: "",
        url: "",
        platform: "",
        notes: "",
        is_active: true,
    });
    const editForm = useForm({
        name: "",
        url: "",
        platform: "",
        notes: "",
        is_active: true,
    });
    const delForm = useForm({});

    const handleAdd = (data) => {
        addForm.setData(data);
        addForm.post("/websites", {
            onSuccess: () => {
                setShowAdd(false);
                addForm.reset();
            },
        });
    };

    const handleEdit = (data) => {
        editForm.setData(data);
        editForm.put(`/websites/${editing.id}`, {
            onSuccess: () => setEditing(null),
        });
    };

    const handleDelete = () => {
        delForm.delete(`/websites/${deleting.id}`, {
            onSuccess: () => setDeleting(null),
        });
    };

    const openEdit = (w) => {
        editForm.reset();
        setEditing(w);
    };

    return (
        <AppLayout title="Websites">
            {/* Header row */}
            <div
                style={{
                    display: "flex",
                    justifyContent: "space-between",
                    alignItems: "center",
                    marginBottom: 28,
                }}
            >
                <div>
                    <div style={{ color: "#6b6b80", fontSize: 13 }}>
                        {websites.length} website
                        {websites.length !== 1 ? "s" : ""} registered
                    </div>
                </div>
                <button
                    onClick={() => setShowAdd(true)}
                    style={{
                        padding: "10px 20px",
                        borderRadius: 9,
                        border: "none",
                        cursor: "pointer",
                        background: "linear-gradient(135deg,#00e5a0,#00b87a)",
                        color: "#000",
                        fontWeight: 700,
                        fontSize: 13,
                        display: "flex",
                        alignItems: "center",
                        gap: 7,
                    }}
                >
                    <span style={{ fontSize: 16 }}>+</span> Add Website
                </button>
            </div>

            {/* Table */}
            {websites.length === 0 ? (
                <div
                    style={{
                        textAlign: "center",
                        padding: "80px 0",
                        border: "1px dashed #2a2a3a",
                        borderRadius: 16,
                        color: "#3d3d52",
                    }}
                >
                    <div style={{ fontSize: 36, marginBottom: 12 }}>◎</div>
                    <div style={{ fontSize: 14 }}>No websites yet</div>
                    <div style={{ fontSize: 12, marginTop: 4 }}>
                        Click "Add Website" to get started
                    </div>
                </div>
            ) : (
                <div
                    style={{
                        background: "#101018",
                        border: "1px solid #1e1e2e",
                        borderRadius: 14,
                        overflow: "hidden",
                    }}
                >
                    <table
                        style={{ width: "100%", borderCollapse: "collapse" }}
                    >
                        <thead>
                            <tr style={{ borderBottom: "1px solid #1e1e2e" }}>
                                {[
                                    "Name",
                                    "URL",
                                    "Platform",
                                    "Jobs",
                                    "Status",
                                    "Actions",
                                ].map((h) => (
                                    <th
                                        key={h}
                                        style={{
                                            padding: "13px 18px",
                                            textAlign: "left",
                                            fontSize: 11,
                                            color: "#3d3d52",
                                            letterSpacing: "0.08em",
                                            textTransform: "uppercase",
                                        }}
                                    >
                                        {h}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {websites.map((w, i) => (
                                <tr
                                    key={w.id}
                                    style={{
                                        borderBottom:
                                            i < websites.length - 1
                                                ? "1px solid #1a1a28"
                                                : "none",
                                        transition: "background 0.1s",
                                    }}
                                    onMouseEnter={(e) =>
                                        (e.currentTarget.style.background =
                                            "#141420")
                                    }
                                    onMouseLeave={(e) =>
                                        (e.currentTarget.style.background =
                                            "transparent")
                                    }
                                >
                                    <td
                                        style={{
                                            padding: "14px 18px",
                                            color: "#e8e8f0",
                                            fontSize: 13,
                                            fontWeight: 600,
                                        }}
                                    >
                                        {w.name}
                                    </td>
                                    <td style={{ padding: "14px 18px" }}>
                                        <a
                                            href={w.url}
                                            target="_blank"
                                            rel="noreferrer"
                                            style={{
                                                color: "#6b6b80",
                                                fontSize: 12,
                                                textDecoration: "none",
                                                display: "block",
                                                maxWidth: 220,
                                                overflow: "hidden",
                                                textOverflow: "ellipsis",
                                                whiteSpace: "nowrap",
                                            }}
                                        >
                                            {w.url}
                                        </a>
                                    </td>
                                    <td style={{ padding: "14px 18px" }}>
                                        <PlatformBadge platform={w.platform} />
                                    </td>
                                    <td
                                        style={{
                                            padding: "14px 18px",
                                            color: "#6b6b80",
                                            fontSize: 13,
                                        }}
                                    >
                                        {w.scrape_jobs_count}
                                    </td>
                                    <td style={{ padding: "14px 18px" }}>
                                        <span
                                            style={{
                                                padding: "2px 8px",
                                                borderRadius: 4,
                                                fontSize: 11,
                                                fontWeight: 600,
                                                background: w.is_active
                                                    ? "rgba(0,229,160,0.08)"
                                                    : "rgba(107,107,128,0.1)",
                                                border: `1px solid ${w.is_active ? "rgba(0,229,160,0.2)" : "#2a2a3a"}`,
                                                color: w.is_active
                                                    ? "#00e5a0"
                                                    : "#6b6b80",
                                            }}
                                        >
                                            {w.is_active
                                                ? "Active"
                                                : "Inactive"}
                                        </span>
                                    </td>
                                    <td style={{ padding: "14px 18px" }}>
                                        <div
                                            style={{ display: "flex", gap: 6 }}
                                        >
                                            <button
                                                onClick={() => openEdit(w)}
                                                style={btnStyle(
                                                    "#2a2a3a",
                                                    "#9090a8",
                                                )}
                                            >
                                                Edit
                                            </button>
                                            <button
                                                onClick={() => setDeleting(w)}
                                                style={btnStyle(
                                                    "rgba(255,107,107,0.1)",
                                                    "#ff6b6b",
                                                )}
                                            >
                                                Delete
                                            </button>
                                        </div>
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}

            {/* Add Modal */}
            {showAdd && (
                <Modal title="Add Website" onClose={() => setShowAdd(false)}>
                    <WebsiteForm
                        onSubmit={handleAdd}
                        processing={addForm.processing}
                        errors={addForm.errors}
                        submitLabel="Add Website"
                    />
                </Modal>
            )}

            {/* Edit Modal */}
            {editing && (
                <Modal title="Edit Website" onClose={() => setEditing(null)}>
                    <WebsiteForm
                        initial={editing}
                        onSubmit={handleEdit}
                        processing={editForm.processing}
                        errors={editForm.errors}
                        submitLabel="Update"
                    />
                </Modal>
            )}

            {/* Delete Confirm */}
            {deleting && (
                <Modal title="Delete Website" onClose={() => setDeleting(null)}>
                    <p
                        style={{
                            color: "#9090a8",
                            fontSize: 14,
                            marginBottom: 24,
                        }}
                    >
                        Are you sure you want to delete{" "}
                        <strong style={{ color: "#fff" }}>
                            {deleting.name}
                        </strong>
                        ? This will also delete all associated scrape jobs and
                        CSV files.
                    </p>
                    <div
                        style={{
                            display: "flex",
                            gap: 10,
                            justifyContent: "flex-end",
                        }}
                    >
                        <button
                            onClick={() => setDeleting(null)}
                            style={btnStyle("#2a2a3a", "#9090a8")}
                        >
                            Cancel
                        </button>
                        <button
                            onClick={handleDelete}
                            disabled={delForm.processing}
                            style={{
                                ...btnStyle(
                                    "rgba(255,107,107,0.15)",
                                    "#ff6b6b",
                                ),
                                border: "1px solid rgba(255,107,107,0.25)",
                                fontWeight: 700,
                            }}
                        >
                            {delForm.processing ? "Deleting..." : "Delete"}
                        </button>
                    </div>
                </Modal>
            )}
        </AppLayout>
    );
}

const btnStyle = (bg, color) => ({
    padding: "6px 14px",
    borderRadius: 6,
    border: `1px solid ${bg}`,
    background: bg,
    color,
    fontSize: 12,
    cursor: "pointer",
    fontWeight: 500,
    transition: "opacity 0.15s",
});
