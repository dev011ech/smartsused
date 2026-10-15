import 'package:flutter/material.dart';
import 'package:lucide_icons_flutter/lucide_icons.dart';

class DashboardScreen extends StatelessWidget {
  const DashboardScreen({super.key});

  @override
  Widget build(BuildContext context) {
    return ListView(
      padding: const EdgeInsets.all(20),
      children: [
        Text(
          'Moja nástenka',
          style: Theme.of(context).textTheme.headlineMedium,
        ),
        const SizedBox(height: 8),
        Text(
          'Oznamy a dôležité informácie pre váš bytový dom.',
          style: Theme.of(context).textTheme.bodyLarge,
        ),
        const SizedBox(height: 20),
        const Card(
          child: Padding(
            padding: EdgeInsets.all(20),
            child: Row(
              children: [
                Icon(LucideIcons.megaphone, size: 32),
                SizedBox(width: 16),
                Expanded(
                  child: Text(
                    'Oznamy sa zobrazia po pripojení komunikačného modulu.',
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}
