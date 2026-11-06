import 'package:flutter/material.dart';
import '../core/theme.dart';

class PageHeading extends StatelessWidget {
  final String title, subtitle;
  const PageHeading(this.title, {super.key, this.subtitle = ''});
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.only(bottom: 14),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Text(
          title,
          style: Theme.of(
            context,
          ).textTheme.headlineSmall?.copyWith(fontSize: 22),
        ),
        if (subtitle.isNotEmpty) ...[
          const SizedBox(height: 4),
          Text(
            subtitle,
            style: const TextStyle(color: MasarColors.muted, fontSize: 12),
          ),
        ],
      ],
    ),
  );
}

class StatCard extends StatelessWidget {
  final String title, value, caption;
  final IconData icon;
  final Color color;
  const StatCard({
    super.key,
    required this.title,
    required this.value,
    required this.icon,
    required this.color,
    this.caption = '',
  });
  @override
  Widget build(BuildContext context) => Container(
    padding: const EdgeInsets.all(14),
    decoration: BoxDecoration(
      color: color.withValues(alpha: .07),
      borderRadius: BorderRadius.circular(17),
      border: Border.all(color: color.withValues(alpha: .22)),
    ),
    child: Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Row(
          children: [
            Container(
              padding: const EdgeInsets.all(8),
              decoration: BoxDecoration(
                color: color.withValues(alpha: .13),
                borderRadius: BorderRadius.circular(11),
              ),
              child: Icon(icon, color: color, size: 19),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: Text(
                title,
                style: const TextStyle(fontSize: 11, color: MasarColors.muted),
                maxLines: 2,
                overflow: TextOverflow.ellipsis,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        FittedBox(
          fit: BoxFit.scaleDown,
          child: Text(
            value,
            style: TextStyle(
              fontSize: 23,
              fontWeight: FontWeight.w800,
              color: color,
            ),
          ),
        ),
        if (caption.isNotEmpty)
          Text(
            caption,
            style: const TextStyle(fontSize: 10, color: MasarColors.muted),
          ),
      ],
    ),
  );
}

class SectionCard extends StatelessWidget {
  final String title;
  final Widget child;
  final Widget? trailing;
  const SectionCard({
    super.key,
    required this.title,
    required this.child,
    this.trailing,
  });
  @override
  Widget build(BuildContext context) => Card(
    child: Padding(
      padding: const EdgeInsets.all(15),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          Row(
            children: [
              Expanded(
                child: Text(
                  title,
                  style: Theme.of(context).textTheme.titleMedium,
                ),
              ),
              if (trailing != null) trailing!,
            ],
          ),
          const SizedBox(height: 12),
          child,
        ],
      ),
    ),
  );
}

class StatusChip extends StatelessWidget {
  final String label;
  const StatusChip(this.label, {super.key});
  @override
  Widget build(BuildContext context) {
    final display =
        const {
          'Approved': 'معتمد',
          'Rejected': 'مرفوض',
          'Active': 'نشط',
          'Draft': 'مسودة',
          'Locked': 'مقفل',
          'Present': 'حاضر',
          'Late': 'متأخر',
          'Absent': 'غائب',
          'Pending': 'قيد المراجعة',
        }[label] ??
        label;
    final color = display == 'معتمد' || display == 'حاضر' || display == 'نشط'
        ? MasarColors.green
        : display == 'مرفوض' || display == 'غائب'
        ? MasarColors.red
        : MasarColors.orange;
    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 9, vertical: 5),
      decoration: BoxDecoration(
        color: color.withValues(alpha: .1),
        borderRadius: BorderRadius.circular(30),
      ),
      child: Text(
        display,
        style: TextStyle(
          color: color,
          fontSize: 10,
          fontWeight: FontWeight.w700,
        ),
      ),
    );
  }
}

class EmptyState extends StatelessWidget {
  final String text;
  const EmptyState(this.text, {super.key});
  @override
  Widget build(BuildContext context) => Padding(
    padding: const EdgeInsets.all(25),
    child: Center(
      child: Column(
        children: [
          Icon(
            Icons.inbox_outlined,
            size: 35,
            color: MasarColors.muted.withValues(alpha: .5),
          ),
          const SizedBox(height: 8),
          Text(text, style: const TextStyle(color: MasarColors.muted)),
        ],
      ),
    ),
  );
}
