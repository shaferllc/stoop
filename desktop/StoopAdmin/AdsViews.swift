import Charts
import SwiftUI

// Sales: the ad inventory and per-day reports (/api/admin/ads, routes/api-admin-ads.php).

struct AdInventory: Decodable {
    let placements: [AdPlacement]
    let csvUrl: String
}

struct AdPlacement: Decodable, Hashable {
    let kind: String
    let id: Int
    let type: String
    let name: String
    let `where`: String
    let starts: String?
    let ends: String?
    let status: String
    let impressions: Int?
    let goal: Int?
    let clicks: Int?
    let ctr: Double?
    let hides: Int
    let reports: Int
    let hasReport: Bool
    var key: String { "\(kind):\(id)" }
}

struct AdReport: Decodable {
    struct Day: Decodable, Identifiable {
        let day: String
        let impressions: Int
        let clicks: Int
        let ctr: Double?
        var id: String { day }
    }

    let days: [Day]
    let impressions: Int
    let clicks: Int
    let ctr: Double?
    let csvUrl: String
}

struct AdsView: View {
    @Environment(AppModel.self) private var model

    var body: some View {
        Loaded(path: "ads") { (inventory: AdInventory) in
            List {
                if inventory.placements.isEmpty {
                    Text("Nothing running or booked.").foregroundStyle(.secondary)
                }
                ForEach(inventory.placements, id: \.key) { p in
                    if p.hasReport {
                        NavigationLink(value: p) { AdRow(p: p) }
                    } else {
                        AdRow(p: p)
                    }
                }
            }
            .navigationSubtitle("Running and upcoming placements")
            .toolbar {
                Button("Inventory CSV", systemImage: "square.and.arrow.down") { model.openOnSite(inventory.csvUrl) }
            }
        }
        .navigationTitle("Ads")
        .navigationDestination(for: AdPlacement.self) { AdReportView(placement: $0) }
    }
}

struct AdRow: View {
    let p: AdPlacement

    var body: some View {
        VStack(alignment: .leading, spacing: 3) {
            HStack {
                Text(p.type.uppercased()).font(.caption2.weight(.semibold)).foregroundStyle(.secondary)
                Pill(p.status == "live" ? "LIVE" : "UPCOMING", red: p.status == "live")
                if p.reports > 0 { Pill("\(p.reports) reported", red: true) }
            }
            Text(p.name).fontWeight(.semibold)
            Text("\(p.where) · \(p.starts ?? "—") to \(p.ends ?? "—")").foregroundStyle(.secondary)
            Text(delivery).font(.callout.monospacedDigit())
        }
        .padding(.vertical, 2)
    }

    var delivery: String {
        var parts: [String] = []
        if let i = p.impressions { parts.append(p.goal.map { "\(i) of \($0) impressions" } ?? "\(i) impressions") }
        if let c = p.clicks { parts.append("\(c) clicks") }
        if let ctr = p.ctr { parts.append(String(format: "%.2f%% CTR", ctr)) }
        if p.hides > 0 { parts.append("hidden \(p.hides)×") }
        return parts.isEmpty ? "No delivery numbers for this kind" : parts.joined(separator: " · ")
    }
}

struct AdReportView: View {
    @Environment(AppModel.self) private var model
    let placement: AdPlacement

    var body: some View {
        Loaded(path: "ads/\(placement.kind)/\(placement.id)") { (r: AdReport) in
            List {
                SwiftUI.Section {
                    Text("\(r.impressions) impressions · \(r.clicks) clicks" + (r.ctr.map { String(format: " · %.2f%% CTR", $0) } ?? ""))
                        .font(.headline.monospacedDigit())
                    if !r.days.isEmpty {
                        Chart(r.days) { d in
                            BarMark(x: .value("Day", d.day), y: .value("Impressions", d.impressions))
                        }
                        .frame(height: 160)
                    }
                }
                SwiftUI.Section("By day") {
                    if r.days.isEmpty { Text("No impressions yet.").foregroundStyle(.secondary) }
                    ForEach(r.days.reversed()) { d in
                        HStack {
                            Text(d.day)
                            Spacer()
                            Text("\(d.impressions) · \(d.clicks) clicks · \(d.ctr.map { String(format: "%.2f%%", $0) } ?? "—")").monospacedDigit().foregroundStyle(.secondary)
                        }
                    }
                }
            }
            .toolbar {
                Button("CSV", systemImage: "square.and.arrow.down") { model.openOnSite(r.csvUrl) }
            }
        }
        .navigationTitle(placement.name)
    }
}
