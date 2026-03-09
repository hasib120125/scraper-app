import { Link, usePage } from "@inertiajs/react";

const NAV = [
    { href: "/dashboard", label: "Dashboard", icon: "▦" },
    { href: "/websites", label: "Websites", icon: "◎" },
    { href: "/scraping", label: "Scraping", icon: "⟳" },
];

export default function AppLayout({ children, title }) {
    const { auth, flash } = usePage().props;
    const current =
        typeof window !== "undefined" ? window.location.pathname : "";

    return (
        <div
            style={{
                display: "flex",
                minHeight: "100vh",
                background: "#0c0c14",
                fontFamily: "'DM Mono', 'Fira Code', monospace",
            }}
        >
            {/* ── Sidebar ─────────────────────────────────────────── */}
            <aside
                style={{
                    width: 220,
                    flexShrink: 0,
                    background: "#101018",
                    borderRight: "1px solid #1e1e2e",
                    display: "flex",
                    flexDirection: "column",
                    padding: "28px 0",
                    position: "sticky",
                    top: 0,
                    height: "100vh",
                }}
            >
                {/* Logo */}
                <div
                    style={{
                        padding: "0 24px 28px",
                        borderBottom: "1px solid #1e1e2e",
                    }}
                >
                    <div
                        style={{
                            display: "flex",
                            alignItems: "center",
                            gap: 10,
                        }}
                    >
                        <div
                            style={{
                                width: 32,
                                height: 32,
                                borderRadius: 8,
                                background:
                                    "linear-gradient(135deg, #00e5a0, #00b87a)",
                                display: "flex",
                                alignItems: "center",
                                justifyContent: "center",
                                fontSize: 16,
                                color: "#000",
                                fontWeight: 700,
                            }}
                        >
                            ⟳
                        </div>
                        <span
                            style={{
                                color: "#fff",
                                fontWeight: 700,
                                fontSize: 15,
                                letterSpacing: "-0.02em",
                            }}
                        >
                            ScrapeKit
                        </span>
                    </div>
                </div>

                {/* Nav */}
                <nav
                    style={{
                        flex: 1,
                        padding: "20px 12px",
                        display: "flex",
                        flexDirection: "column",
                        gap: 2,
                    }}
                >
                    {NAV.map(({ href, label, icon }) => {
                        const active =
                            current === href || current.startsWith(href + "/");
                        return (
                            <Link
                                key={href}
                                href={href}
                                style={{
                                    display: "flex",
                                    alignItems: "center",
                                    gap: 10,
                                    padding: "9px 12px",
                                    borderRadius: 8,
                                    color: active ? "#00e5a0" : "#6b6b80",
                                    background: active
                                        ? "rgba(0,229,160,0.08)"
                                        : "transparent",
                                    textDecoration: "none",
                                    fontSize: 13,
                                    fontWeight: active ? 600 : 400,
                                    transition: "all 0.15s",
                                    border: active
                                        ? "1px solid rgba(0,229,160,0.15)"
                                        : "1px solid transparent",
                                }}
                            >
                                <span style={{ fontSize: 15 }}>{icon}</span>
                                {label}
                            </Link>
                        );
                    })}
                </nav>

                {/* User */}
                <div
                    style={{
                        padding: "20px 16px 0",
                        borderTop: "1px solid #1e1e2e",
                    }}
                >
                    <div
                        style={{
                            fontSize: 12,
                            color: "#3d3d52",
                            marginBottom: 8,
                        }}
                    >
                        Signed in as
                    </div>
                    <div
                        style={{
                            fontSize: 13,
                            color: "#9090a8",
                            marginBottom: 12,
                            overflow: "hidden",
                            textOverflow: "ellipsis",
                            whiteSpace: "nowrap",
                        }}
                    >
                        {auth?.user?.email}
                    </div>
                    <Link
                        href="/logout"
                        method="post"
                        as="button"
                        style={{
                            width: "100%",
                            padding: "7px 12px",
                            borderRadius: 7,
                            background: "transparent",
                            border: "1px solid #1e1e2e",
                            color: "#6b6b80",
                            fontSize: 12,
                            cursor: "pointer",
                            textAlign: "left",
                            transition: "all 0.15s",
                        }}
                    >
                        Sign out →
                    </Link>
                </div>
            </aside>

            {/* ── Main content ────────────────────────────────────── */}
            <div
                style={{
                    flex: 1,
                    display: "flex",
                    flexDirection: "column",
                    overflow: "hidden",
                }}
            >
                {/* Top bar */}
                <header
                    style={{
                        padding: "18px 36px",
                        borderBottom: "1px solid #1e1e2e",
                        display: "flex",
                        alignItems: "center",
                        justifyContent: "space-between",
                        background: "#101018",
                        position: "sticky",
                        top: 0,
                        zIndex: 10,
                    }}
                >
                    <h1
                        style={{
                            color: "#fff",
                            fontSize: 18,
                            fontWeight: 700,
                            letterSpacing: "-0.02em",
                        }}
                    >
                        {title}
                    </h1>
                    <div
                        style={{
                            fontSize: 12,
                            color: "#3d3d52",
                            fontFamily: "monospace",
                        }}
                    >
                        {new Date().toLocaleDateString("en-US", {
                            weekday: "short",
                            month: "short",
                            day: "numeric",
                        })}
                    </div>
                </header>

                {/* Flash messages */}
                {flash?.success && (
                    <div
                        style={{
                            margin: "20px 36px 0",
                            padding: "12px 16px",
                            borderRadius: 8,
                            background: "rgba(0,229,160,0.08)",
                            border: "1px solid rgba(0,229,160,0.2)",
                            color: "#00e5a0",
                            fontSize: 13,
                        }}
                    >
                        ✓ {flash.success}
                    </div>
                )}
                {flash?.error && (
                    <div
                        style={{
                            margin: "20px 36px 0",
                            padding: "12px 16px",
                            borderRadius: 8,
                            background: "rgba(255,107,107,0.08)",
                            border: "1px solid rgba(255,107,107,0.2)",
                            color: "#ff6b6b",
                            fontSize: 13,
                        }}
                    >
                        ✕ {flash.error}
                    </div>
                )}

                {/* Page content */}
                <main
                    style={{ flex: 1, padding: "32px 36px", overflowY: "auto" }}
                >
                    {children}
                </main>
            </div>
        </div>
    );
}
